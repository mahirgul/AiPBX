<?php
/**
 * Kişisel Telefonum & Çağrı Yönetimi View
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
?>

<style>
.my-phone-header-card {
    padding: 12px 18px;
    border-left: 4px solid var(--primary);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.my-phone-badges-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 11.5px;
}
.my-phone-filter-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    align-items: center;
    background: var(--bg-input);
    padding: 14px 16px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
}
.my-phone-filter-buttons {
    display: inline-flex;
    gap: 4px;
    background: var(--bg-card);
    padding: 3px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
}
.my-phone-settings-grid {
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    align-items: start;
}
.my-phone-modes-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

@media (max-width: 768px) {
    .my-phone-header-card {
        padding: 12px 14px !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
    }
    .my-phone-badges-wrapper {
        width: 100% !important;
        gap: 6px !important;
    }
    .my-phone-badges-wrapper .badge {
        padding: 4px 7px !important;
        font-size: 11px !important;
    }
    .my-phone-filter-bar {
        padding: 10px 12px !important;
        gap: 8px !important;
    }
    .my-phone-filter-buttons {
        width: 100% !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
    .my-phone-filter-buttons button {
        flex: 1 1 auto !important;
        padding: 6px 8px !important;
        white-space: nowrap !important;
        font-size: 11.5px !important;
    }
    .my-phone-search-wrapper {
        min-width: 100% !important;
        max-width: 100% !important;
    }
    #tab-pane-history {
        padding: 14px 12px !important;
    }
}

@media (max-width: 480px) {
    .my-phone-modes-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<!-- 1. Üst Dahili & Durum Çubuğu -->
    <div class="card mb-3 my-phone-header-card">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(0, 242, 254, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                <i class="fas fa-phone-alt"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <h2 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main);">
                        <?php echo htmlspecialchars($details['full_name'] ?? ''); ?>
                    </h2>
                    <?php if ($ext !== ''): ?>
                        <span class="badge" style="font-size: 12px; padding: 2px 8px; background: var(--primary); color: #fff; font-weight: 700; border-radius: 12px;">
                            #<?php echo htmlspecialchars($ext); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                    <i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($details['role'] ?? ''); ?>
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
                <i class="fas fa-mobile-alt"></i> Mobil: 
                <strong style="color: <?php echo $isMobileOnline ? 'var(--success)' : 'var(--text-muted)'; ?>;">
                    <?php echo $isMobileOnline ? t('my_phone.mobile_status_online') : t('my_phone.mobile_status_offline'); ?>
                </strong>
            </span>
            <!-- DND Rozeti -->
            <?php if ($isDnd): ?>
                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.3); padding: 5px 10px; font-size: 11.5px; border-radius: 8px;">
                    <i class="fas fa-minus-circle"></i> DND Aktif
                </span>
            <?php endif; ?>
            <!-- Çağrı Yönlendirme Rozeti -->
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
        <!-- 3. Sekmeli Gezinme Butonları -->
        <div style="display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
            <button type="button" class="btn btn-sm <?php echo $currentTab === 'history' ? 'btn-primary' : 'btn-secondary'; ?>" id="btn-tab-history" onclick="switchMyPhoneTab('history')" style="border-radius: 8px; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px;">
                <i class="fas fa-history"></i> <?php echo t('my_phone.tab_history'); ?>
                <span class="badge" style="background: rgba(0,0,0,0.15); font-size: 11px; padding: 2px 7px; border-radius: 10px;"><?php echo count($calls); ?></span>
            </button>
            <button type="button" class="btn btn-sm <?php echo $currentTab === 'settings' ? 'btn-primary' : 'btn-secondary'; ?>" id="btn-tab-settings" onclick="switchMyPhoneTab('settings')" style="border-radius: 8px; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px;">
                <i class="fas fa-sliders-h"></i> <?php echo t('my_phone.tab_settings'); ?>
                <?php if ($isDnd || $hasActiveCf): ?>
                    <span class="badge badge-warning" style="font-size: 10px; padding: 2px 6px; border-radius: 10px;"><i class="fas fa-check"></i> Aktif</span>
                <?php endif; ?>
            </button>
        </div>

        <!-- TAB 1: ÇAĞRI GEÇMİŞİM (Geniş & Modern Data-Table) -->
        <div id="tab-pane-history" class="card" style="display: <?php echo $currentTab === 'history' ? 'block' : 'none'; ?>; padding: 24px; border-radius: 14px;">
            <div class="card-header" style="padding: 0 0 16px 0; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;">
                <div class="card-title" style="font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-phone-volume" style="color: var(--primary);"></i> <?php echo t('my_phone.recent_calls'); ?>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <a href="/my-phone?tab=history" class="btn btn-secondary btn-sm" title="Yenile"><i class="fas fa-sync-alt"></i></a>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="switchMyPhoneTab('settings')" title="Telefon Ayarlarına Git">
                        <i class="fas fa-sliders-h"></i> <?php echo t('my_phone.tab_settings'); ?>
                    </button>
                </div>
            </div>

            <!-- Filtreleme & Arama Çubuğu (CDR Raporları ile Birebir Uyumlu) -->
            <form method="GET" action="/my-phone" id="myPhoneFilterForm" class="my-phone-filter-bar">
                <input type="hidden" name="tab" value="history">
                <input type="hidden" name="filter" id="myPhoneFilterInput" value="<?php echo htmlspecialchars($filter); ?>">

                <!-- Yön Filtre Butonları -->
                <div class="my-phone-filter-buttons">
                    <button type="button" onclick="setMyPhoneFilter('all')" class="btn btn-xs <?php echo $filter === 'all' ? 'btn-primary' : 'btn-ghost'; ?>" style="border-radius: 6px; padding: 5px 12px; font-weight: 600; font-size: 12px;">
                        <?php echo t('my_phone.filter_all'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('in')" class="btn btn-xs <?php echo $filter === 'in' ? 'btn-primary' : 'btn-ghost'; ?>" style="border-radius: 6px; padding: 5px 12px; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-arrow-down" style="color: var(--success);"></i> <?php echo t('my_phone.filter_in'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('out')" class="btn btn-xs <?php echo $filter === 'out' ? 'btn-primary' : 'btn-ghost'; ?>" style="border-radius: 6px; padding: 5px 12px; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-arrow-up" style="color: var(--primary);"></i> <?php echo t('my_phone.filter_out'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('missed')" class="btn btn-xs <?php echo $filter === 'missed' ? 'btn-primary' : 'btn-ghost'; ?>" style="border-radius: 6px; padding: 5px 12px; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-phone-slash" style="color: var(--danger);"></i> <?php echo t('my_phone.filter_missed'); ?>
                    </button>
                </div>

                <!-- Arama Kutusu -->
                <div class="my-phone-search-wrapper" style="position: relative; flex: 1; min-width: 200px; max-width: 380px;">
                    <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; pointer-events: none;"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control form-control-sm" placeholder="Numara veya kişi adı ara..." style="padding-left: 32px; border-radius: 8px;">
                </div>

                <button type="submit" class="btn btn-primary btn-sm" title="Filtrele" style="border-radius: 8px; padding: 0 14px; height: 34px;">
                    <i class="fas fa-filter"></i>
                </button>

                <?php if ($search !== '' || $filter !== 'all'): ?>
                    <a href="/my-phone?tab=history" class="btn btn-secondary btn-sm" title="Sıfırla" style="border-radius: 8px; padding: 0 12px; height: 34px; display: inline-flex; align-items: center;">
                        <i class="fas fa-undo"></i>
                    </a>
                <?php endif; ?>
            </form>

            <!-- CDR Görünümlü Zengin Data Table -->
            <div class="table-responsive">
                <table class="data-table" data-no-dt="true">
                    <thead>
                        <tr>
                            <th class="col-hide-mobile" style="width: 45px;">#</th>
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
                                    <div style="font-weight: 600; font-size: 14px; color: var(--text-main); margin-bottom: 4px;">Çağrı kaydı bulunamadı</div>
                                    <small style="font-size: 12px;">Seçilen kriterlere uygun çağrı geçmişi bulunmuyor.</small>
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
                                    $dispLabel = 'Cevaplandı';
                                } elseif ($disp === 'BUSY') {
                                    $badgeClass = 'badge-info';
                                    $dispLabel = 'Meşgul';
                                } elseif (in_array($disp, ['NO ANSWER', 'NOANSWER', 'CANCEL'])) {
                                    $badgeClass = 'badge-warning';
                                    $dispLabel = 'Cevapsız';
                                } else {
                                    $badgeClass = 'badge-danger';
                                    $dispLabel = htmlspecialchars($disp);
                                }
                            ?>
                                <tr>
                                    <!-- ID -->
                                    <td class="col-hide-mobile" style="color: var(--text-muted); font-size: 12px;">#<?php echo $c['id']; ?></td>
                                    
                                    <!-- Tarih & Saat -->
                                    <td style="font-weight: 600; white-space: nowrap;">
                                        <?php echo date('d.m.Y H:i:s', strtotime($c['calldate'])); ?>
                                    </td>

                                    <!-- Yön Rozeti -->
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

                                    <!-- Karşı Taraf -->
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary); font-size: 13.5px; display: flex; align-items: center; gap: 6px;">
                                            <i class="fas fa-phone-alt" style="font-size: 11px; opacity: 0.7;"></i>
                                            <span><?php echo $party; ?></span>
                                        </div>
                                        <?php if (!empty($partyName) && $partyName !== $party): ?>
                                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                                <i class="fas fa-user" style="font-size: 10px; margin-right: 3px;"></i><?php echo $partyName; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Cihaz -->
                                    <td class="col-hide-mobile">
                                        <?php if ($dev === 'mobil'): ?>
                                            <span class="badge" style="background: rgba(13, 202, 240, 0.15); color: #087990; border: 1px solid rgba(13, 202, 240, 0.35); font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-mobile-alt"></i> Mobil
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
                                            <span style="color: var(--text-muted); font-size: 12px;">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Süre -->
                                    <td>
                                        <?php if ($disp === 'ANSWERED'): ?>
                                            <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--success);">
                                                <i class="fas fa-phone-volume" style="font-size: 11px; margin-right: 4px;"></i><?php echo $sureBicim($bill); ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px; white-space: nowrap;">
                                                Çalma: <?php echo $sureBicim($ring); ?> • Toplam: <?php echo $sureBicim($dur); ?>
                                            </div>
                                        <?php elseif (in_array($disp, ['NO ANSWER', 'NOANSWER', 'CANCEL', 'BUSY'])): ?>
                                            <div style="font-family: monospace; font-size: 12px; color: var(--warning); font-weight: 600;">
                                                <i class="fas fa-bell" style="font-size: 10px; margin-right: 3px;"></i>Çalma: <?php echo $sureBicim($ring > 0 ? $ring : $dur); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 12px; font-family: monospace;">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Durum -->
                                    <td>
                                        <span class="badge <?php echo $badgeClass; ?>" style="font-size: 11px;">
                                            <?php echo $dispLabel; ?>
                                        </span>
                                    </td>

                                    <!-- Aksiyonlar -->
                                    <td class="text-right" style="white-space: nowrap;">
                                        <div style="display: inline-flex; align-items: center; gap: 6px;">
                                            <?php if ($hasRec): ?>
                                                <button type="button" class="btn btn-secondary btn-sm" onclick="playCdrAudio(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($party), ENT_QUOTES); ?>', '<?php echo date('d.m.Y H:i', strtotime($c['calldate'])); ?>')" title="Ses Kaydını Dinle" style="padding: 4px 8px;">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                                <a href="/api/cc_audio.php?id=<?php echo $c['id']; ?>&download=1" class="btn btn-secondary btn-sm" title="Ses Kaydını İndir" style="padding: 4px 8px;">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" onclick="callTargetNumber('<?php echo $party; ?>')" title="<?php echo $party; ?> Numarasını Ara" style="border-radius: 6px; padding: 4px 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
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

        <!-- TAB 2: TELEFON & CİHAZ AYARLARI (Geniş 3 Kolonlu Izgara Düzeni) -->
        <div id="tab-pane-settings" class="my-phone-settings-grid" style="display: <?php echo $currentTab === 'settings' ? 'grid' : 'none'; ?>;">
            
            <!-- Kart 1: Telefon & Yönlendirme Ayarları -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 18px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-sliders-h" style="color: var(--primary);"></i> <?php echo t('my_phone.settings_title'); ?>
                </h3>

                <form method="POST" action="/my-phone">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">

                    <!-- Rahatsız Etmeyin (DND) Switch -->
                    <div class="form-group" style="background: var(--bg-input); padding: 14px; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 16px;">
                        <label style="display: flex; align-items: center; justify-content: space-between; cursor: pointer; margin: 0;">
                            <div>
                                <div style="font-weight: 600; font-size: 13px; color: var(--text-main);">
                                    <i class="fas fa-minus-circle" style="color: var(--danger);"></i> <?php echo t('my_phone.dnd_label'); ?>
                                </div>
                                <small style="color: var(--text-muted); font-size: 11px; display: block; margin-top: 2px;">
                                    <?php echo t('my_phone.dnd_desc'); ?>
                                </small>
                            </div>
                            <input type="checkbox" name="dnd_enabled" value="1" <?php echo $isDnd ? 'checked' : ''; ?> style="width: 20px; height: 20px; cursor: pointer;">
                        </label>
                    </div>

                    <!-- Çağrı Yönlendirme Seçenekleri (Her Zaman, Meşgulken, Cevapsızken) -->
                    <div style="background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 10px; padding: 16px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                            <div style="font-weight: 700; font-size: 13px; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-share" style="color: var(--warning);"></i> <?php echo t('my_phone.cf_card_title'); ?>
                            </div>
                            <?php
                                $hasActiveCf = !empty($cfNum) || !empty($cfBusyNum) || !empty($cfNoAnsNum);
                            ?>
                            <span class="badge <?php echo $hasActiveCf ? 'badge-warning' : 'badge-secondary'; ?>" style="font-size: 10.5px; font-weight: 600;">
                                <i class="fas <?php echo $hasActiveCf ? 'fa-check-circle' : 'fa-ban'; ?>"></i>
                                <?php echo $hasActiveCf ? t('my_phone.cf_status_active') : t('my_phone.cf_status_disabled'); ?>
                            </span>
                        </div>

                        <!-- 1. Her Zaman Yönlendir (Koşulsuz) -->
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label class="form-label" style="font-size: 11.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span><i class="fas fa-forward" style="color: var(--warning); margin-right: 4px;"></i> <?php echo t('my_phone.cf_always_label'); ?></span>
                                <?php if (!empty($cfNum)): ?>
                                    <span class="badge badge-warning" style="font-size: 9.5px; padding: 2px 6px;"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="call_forward_number" value="<?php echo htmlspecialchars($cfNum); ?>" class="form-control form-control-sm" placeholder="<?php echo t('my_phone.cf_placeholder_internal_external'); ?>" style="font-size: 12.5px;">
                            <small style="color: var(--text-muted); font-size: 10.5px; display: block; margin-top: 2px;">
                                <?php echo t('my_phone.cf_always_help'); ?>
                            </small>
                        </div>

                        <!-- 2. Meşgulken Yönlendir -->
                        <div class="form-group" style="margin-bottom: 14px;">
                            <label class="form-label" style="font-size: 11.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span><i class="fas fa-phone-slash" style="color: var(--danger); margin-right: 4px;"></i> <?php echo t('my_phone.cf_busy_label'); ?></span>
                                <?php if (!empty($cfBusyNum)): ?>
                                    <span class="badge badge-info" style="font-size: 9.5px; padding: 2px 6px;"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="cf_busy_number" value="<?php echo htmlspecialchars($cfBusyNum); ?>" class="form-control form-control-sm" placeholder="<?php echo t('my_phone.cf_placeholder_internal_external'); ?>" style="font-size: 12.5px;">
                            <small style="color: var(--text-muted); font-size: 10.5px; display: block; margin-top: 2px;">
                                <?php echo t('my_phone.cf_busy_help'); ?>
                            </small>
                        </div>

                        <!-- 3. Cevapsızken Yönlendir -->
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 11.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span><i class="fas fa-phone-volume" style="color: var(--info); margin-right: 4px;"></i> <?php echo t('my_phone.cf_noanswer_label'); ?></span>
                                <?php if (!empty($cfNoAnsNum)): ?>
                                    <span class="badge badge-info" style="font-size: 9.5px; padding: 2px 6px;"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <div style="display: flex; gap: 8px;">
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

                    <!-- Aktif Telefon Modu (Web, Mobil, SIP, Görüntü) -->
                    <div class="form-group" style="background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 10px; padding: 14px; margin-bottom: 18px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                            <label class="form-label" style="font-size: 12px; font-weight: 700; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-phone-volume" style="color: var(--primary);"></i> <?php echo t('my_phone.active_phone_modes'); ?>
                            </label>
                            <span class="badge badge-info" style="font-size: 10px; font-weight: 600;">
                                <?php echo count($activeModes); ?> / 4 <?php echo t('my_phone.modes_active'); ?>
                            </span>
                        </div>
                        <small style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 12px; line-height: 1.4;">
                            <?php echo t('my_phone.phone_modes_desc'); ?>
                        </small>

                        <div class="my-phone-modes-grid">
                            <!-- Web (Tarayıcı) -->
                            <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">
                                <input type="checkbox" name="phone_modes[]" value="web" <?php echo in_array('web', $activeModes, true) ? 'checked' : ''; ?> style="width: 17px; height: 17px; cursor: pointer; accent-color: var(--primary);">
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600; font-size: 12.5px; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-laptop" style="color: var(--primary); font-size: 12px;"></i> <?php echo t('my_phone.mode_web'); ?>
                                    </span>
                                    <small style="color: var(--text-muted); font-size: 10px;"><?php echo t('my_phone.mode_web_sub'); ?></small>
                                </div>
                            </label>

                            <!-- Mobil (Uygulama) -->
                            <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">
                                <input type="checkbox" name="phone_modes[]" value="mobil" <?php echo in_array('mobil', $activeModes, true) ? 'checked' : ''; ?> style="width: 17px; height: 17px; cursor: pointer; accent-color: var(--success);">
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600; font-size: 12.5px; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-mobile-alt" style="color: var(--success); font-size: 12px;"></i> <?php echo t('my_phone.mode_mobil'); ?>
                                    </span>
                                    <small style="color: var(--text-muted); font-size: 10px;"><?php echo t('my_phone.mode_mobil_sub'); ?></small>
                                </div>
                            </label>

                            <!-- SIP (Masaüstü) -->
                            <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">
                                <input type="checkbox" name="phone_modes[]" value="sip" <?php echo in_array('sip', $activeModes, true) ? 'checked' : ''; ?> style="width: 17px; height: 17px; cursor: pointer; accent-color: var(--secondary);">
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600; font-size: 12.5px; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-phone-alt" style="color: var(--secondary); font-size: 12px;"></i> <?php echo t('my_phone.mode_sip_desk'); ?>
                                    </span>
                                    <small style="color: var(--text-muted); font-size: 10px;"><?php echo t('my_phone.mode_sip_sub'); ?></small>
                                </div>
                            </label>

                            <!-- Görüntü (Video) -->
                            <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">
                                <input type="checkbox" name="phone_modes[]" value="video" <?php echo in_array('video', $activeModes, true) ? 'checked' : ''; ?> style="width: 17px; height: 17px; cursor: pointer; accent-color: #8b5cf6;">
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600; font-size: 12.5px; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-video" style="color: #8b5cf6; font-size: 12px;"></i> <?php echo t('my_phone.mode_video'); ?>
                                    </span>
                                    <small style="color: var(--text-muted); font-size: 10px;"><?php echo t('my_phone.mode_video_sub'); ?></small>
                                </div>
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
                </form>
            </div>

            <!-- Kart 2: WebRTC Aygıt & Zil Sesi Ayarları -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 18px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-headphones" style="color: var(--primary);"></i> <?php echo t('my_phone.device_settings'); ?>
                </h3>

                <!-- Mikrofon -->
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 12px; font-weight: 600;">
                        <i class="fas fa-microphone"></i> <?php echo t('phone_settings.field_mic'); ?>
                    </label>
                    <select id="my_phone_mic_select" class="form-control" onchange="savePhoneMicDevice(this.value)" style="font-size: 12.5px;">
                        <option value="default">Sistem Varsayılanı</option>
                    </select>
                </div>

                <!-- Hoparlör -->
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 12px; font-weight: 600;">
                        <i class="fas fa-volume-up"></i> <?php echo t('phone_settings.field_speaker'); ?>
                    </label>
                    <div style="display: flex; gap: 8px;">
                        <select id="my_phone_speaker_select" class="form-control" onchange="savePhoneSpeakerDevice(this.value)" style="flex: 1; font-size: 12.5px;">
                            <option value="default">Sistem Varsayılanı</option>
                        </select>
                        <button type="button" class="btn btn-secondary" onclick="testPhoneSpeaker()" title="<?php echo t('phone_settings.test_sound_tooltip'); ?>" style="padding: 0 14px;">
                            <i class="fas fa-play"></i>
                        </button>
                    </div>
                </div>

                <!-- Zil Sesi Seviyesi -->
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 12px; font-weight: 600; display: flex; justify-content: space-between;">
                        <span><i class="fas fa-bell"></i> <?php echo t('phone_settings.field_ring_volume'); ?></span>
                        <span id="my-phone-vol-label" style="color: var(--primary); font-weight: 700;">100%</span>
                    </label>
                    <input type="range" id="my_phone_ring_slider" min="0" max="100" value="100" style="width: 100%; cursor: pointer;" oninput="updateVolSlider(this.value)">
                </div>
            </div>

            <!-- Kart 3: Masaüstü IP Telefonu Kayıt Bilgileri (Credentials) -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 18px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-key" style="color: var(--primary);"></i> <?php echo t('my_phone.sip_credentials_title'); ?>
                </h3>
                <div style="font-size: 12.5px; background: var(--bg-input); padding: 16px; border-radius: 10px; border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-muted);"><?php echo t('my_phone.sip_server'); ?>:</span>
                        <strong style="font-family: monospace;"><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? '127.0.0.1'); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-muted);"><?php echo t('my_phone.sip_port'); ?>:</span>
                        <strong style="font-family: monospace;">5060 (UDP/TCP)</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-muted);"><?php echo t('my_phone.sip_username'); ?>:</span>
                        <strong style="font-family: monospace; color: var(--primary);"><?php echo htmlspecialchars($ext); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--text-muted);"><?php echo t('my_phone.sip_password'); ?>:</span>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="password" id="my_phone_sip_pass_val" value="<?php echo htmlspecialchars($details['sip_password'] ?? ''); ?>" readonly style="background: transparent; border: none; font-family: monospace; width: 110px; text-align: right; color: var(--text-main);" />
                            <button type="button" class="btn btn-xs btn-ghost" onclick="toggleSipPassVisibility()" style="padding: 2px 6px;" title="Göster / Gizle">
                                <i class="fas fa-eye" id="my_phone_pass_eye"></i>
                            </button>
                            <button type="button" class="btn btn-xs btn-ghost" onclick="copySipPassword()" style="padding: 2px 6px;" title="Kopyala">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kart 4: Mobil Uygulama & Cihaz Bilgileri -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 18px; color: var(--text-main); display: flex; align-items: center; justify-content: space-between;">
                    <span style="display: flex; align-items: center; gap: 8px;">
                        <i class="fab fa-android" style="color: #3DDC84;"></i> Mobil Uygulama &amp; Sürüm Bilgisi
                    </span>
                    <a href="/app.apk" class="btn btn-xs btn-primary" download style="font-size: 11px; padding: 4px 10px;">
                        <i class="fas fa-download"></i> APK İndir
                    </a>
                </h3>

                <?php if (empty($mobileDevices)): ?>
                    <div style="font-size: 12.5px; background: var(--bg-input); padding: 16px; border-radius: 10px; border: 1px solid var(--border-color); color: var(--text-muted); text-align: center;">
                        <i class="fas fa-mobile-alt" style="font-size: 24px; margin-bottom: 8px; opacity: 0.5; display: block;"></i>
                        Bu dahili için henüz kayıtlı bir mobil uygulama cihazı bulunmuyor.<br>
                        Android uygulamasını cihazınıza yükleyip bu dahili ile giriş yaptığınızda cihaz ve sürüm bilgisi burada görüntülenecektir.
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($mobileDevices as $md): ?>
                            <div style="font-size: 12.5px; background: var(--bg-input); padding: 14px 16px; border-radius: 10px; border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <strong style="font-size: 13px; color: var(--text-main);">
                                        <i class="fas fa-mobile-alt" style="margin-right: 6px; color: var(--primary);"></i>
                                        <?php echo htmlspecialchars($md['device_name'] ?: 'Android Cihaz'); ?>
                                    </strong>
                                    <span class="badge badge-success" style="font-size: 10px; padding: 3px 8px;">
                                        v<?php echo htmlspecialchars($md['app_version'] ?: '1.0'); ?>
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between; color: var(--text-muted); font-size: 11.5px;">
                                    <span>Platform / Sistem:</span>
                                    <span><?php echo htmlspecialchars(ucfirst($md['platform'])); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; color: var(--text-muted); font-size: 11.5px;">
                                    <span>Son Senkronizasyon:</span>
                                    <span><?php echo htmlspecialchars($md['updated_at']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    <?php endif; ?>
</div>

<!-- WaveSurfer Ses Oynatıcı Modal (CDR Raporları ile Ortak Bileşen) -->
<div class="modal-overlay" id="cdrAudioModal">
    <div class="modal-card" style="max-width: 620px;">
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(0, 242, 254, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                    <i class="fas fa-file-audio"></i>
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; margin: 0;" id="cdrModalTitle">Görüşme Kaydı</h3>
                    <small style="color: var(--text-muted); font-size: 11px;" id="cdrModalInfo">Arayan: -</small>
                </div>
            </div>
            <button class="btn btn-secondary" onclick="closeCdrAudioModal()" style="padding: 6px 12px;" title="Kapat"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <!-- Dalga Formu Görselleştirici -->
            <div style="background: rgba(0, 0, 0, 0.04); padding: 16px; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 16px; position: relative;">
                <div id="cdrWaveform" style="width: 100%; min-height: 90px;"></div>
                <div id="cdrWaveformLoading" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.85); border-radius: 12px; font-size: 13px; color: var(--primary); gap: 8px; font-weight: 600; z-index: 5;">
                    <i class="fas fa-spinner fa-spin"></i> Kayıt yükleniyor...
                </div>
            </div>

            <!-- Kontrol Butonları -->
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button class="btn btn-primary" id="cdrWavePlayBtn" onclick="toggleCdrWavePlay()" style="min-width: 44px;" title="Oynat / Duraklat">
                        <i class="fas fa-play"></i>
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="cdrWaveSkip(-5)" title="5sn Geri">
                        <i class="fas fa-undo"></i> -5s
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="cdrWaveSkip(5)" title="5sn İleri">
                        <i class="fas fa-redo"></i> +5s
                    </button>
                    <a id="cdrDownloadLink" href="#" class="btn btn-secondary btn-sm" title="Kaydı İndir">
                        <i class="fas fa-download"></i>
                    </a>
                </div>

                <!-- Süre Göstergesi -->
                <div style="font-family: monospace; font-size: 14px; font-weight: 700; color: var(--primary); background: rgba(0, 242, 254, 0.1); padding: 6px 14px; border-radius: 8px;">
                    <span id="cdrCurrentTime">00:00</span> / <span id="cdrTotalDuration">00:00</span>
                </div>

                <!-- Ses Seviyesi -->
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button class="btn btn-secondary btn-sm" id="cdrMuteBtn" onclick="toggleCdrMute()" style="padding: 6px 10px;" title="Sesi Aç / Kapat">
                        <i class="fas fa-volume-up" id="cdrMuteIcon"></i>
                    </button>
                    <input type="range" id="cdrVolumeSlider" min="0" max="1" step="0.05" value="1" style="width: 80px; cursor: pointer;" oninput="setCdrVolume(this.value)">
                </div>
            </div>
        </div>
    </div>

<script src="/assets/js/wavesurfer.min.js"></script>
<script src="/assets/js/cdr_reports.js?v=<?php echo time(); ?>"></script>

<script>
/**
 * Kişisel Telefonum Sayfası JS İşlevleri
 */
function switchMyPhoneTab(tabName) {
    const paneHistory = document.getElementById("tab-pane-history");
    const paneSettings = document.getElementById("tab-pane-settings");
    const btnHistory = document.getElementById("btn-tab-history");
    const btnSettings = document.getElementById("btn-tab-settings");

    if (tabName === "settings") {
        if (paneHistory) paneHistory.style.display = "none";
        if (paneSettings) paneSettings.style.display = "grid";
        if (btnHistory) {
            btnHistory.classList.remove("btn-primary");
            btnHistory.classList.add("btn-secondary");
        }
        if (btnSettings) {
            btnSettings.classList.remove("btn-secondary");
            btnSettings.classList.add("btn-primary");
        }
        history.replaceState(null, "", "/my-phone?tab=settings");
        localStorage.setItem("my_phone_active_tab", "settings");
        if (typeof loadMyPhoneAudioDevices === "function") {
            loadMyPhoneAudioDevices();
        }
    } else {
        if (paneHistory) paneHistory.style.display = "block";
        if (paneSettings) paneSettings.style.display = "none";
        if (btnHistory) {
            btnHistory.classList.remove("btn-secondary");
            btnHistory.classList.add("btn-primary");
        }
        if (btnSettings) {
            btnSettings.classList.remove("btn-primary");
            btnSettings.classList.add("btn-secondary");
        }
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete("tab");
        const queryStr = urlParams.toString();
        history.replaceState(null, "", "/my-phone" + (queryStr ? "?" + queryStr : ""));
        localStorage.setItem("my_phone_active_tab", "history");
    }
}

function setMyPhoneFilter(filterVal) {
    const input = document.getElementById("myPhoneFilterInput");
    const form = document.getElementById("myPhoneFilterForm");
    if (input && form) {
        input.value = filterVal;
        form.submit();
    }
}

function callTargetNumber(number) {
    if (!number) return;
    const input = document.getElementById("header-quick-dial-input");
    if (input) {
        input.value = number;
    }
    if (typeof headerPhoneMakeCall === "function") {
        headerPhoneMakeCall();
    } else {
        alert("WebRTC Softphone başlatılamadı.");
    }
}

function toggleSipPassVisibility() {
    const passInput = document.getElementById("my_phone_sip_pass_val");
    const eye = document.getElementById("my_phone_pass_eye");
    if (!passInput) return;
    if (passInput.type === "password") {
        passInput.type = "text";
        if (eye) eye.className = "fas fa-eye-slash";
    } else {
        passInput.type = "password";
        if (eye) eye.className = "fas fa-eye";
    }
}

function copySipPassword() {
    const passInput = document.getElementById("my_phone_sip_pass_val");
    if (!passInput || !passInput.value) return;
    navigator.clipboard.writeText(passInput.value).then(function() {
        if (window.showFooterToast) {
            showFooterToast("SIP parolası panoya kopyalandı!", "success");
        }
    });
}

function updateVolSlider(val) {
    const lbl = document.getElementById("my-phone-vol-label");
    if (lbl) lbl.textContent = val + "%";
    if (typeof savePhoneRingVolume === "function") {
        savePhoneRingVolume(val);
    }
}

function loadMyPhoneAudioDevices() {
    if (typeof populatePhoneDeviceSelects === "function") {
        populatePhoneDeviceSelects();
    }
}

function initMyPhone() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get("tab");
    if (tabParam === "settings" || (!tabParam && localStorage.getItem("my_phone_active_tab") === "settings")) {
        switchMyPhoneTab("settings");
    } else {
        loadMyPhoneAudioDevices();
    }

    // WebRTC durumunu softphone ile senkronize et
    function syncMyPhoneWebrtc() {
        const txt = document.getElementById("my-phone-webrtc-text");
        if (!txt) return;
        if (typeof headerSipRegistered !== "undefined" && headerSipRegistered) {
            txt.textContent = "Çevrimiçi";
            txt.style.color = "var(--success)";
        }
    }
    syncMyPhoneWebrtc();
    if (!window._myPhoneSyncInterval) {
        window._myPhoneSyncInterval = setInterval(syncMyPhoneWebrtc, 1000);
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initMyPhone);
} else {
    initMyPhone();
}
if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
    navigator.mediaDevices.addEventListener("devicechange", loadMyPhoneAudioDevices);
}
</script>
