<?php
/**
 * Conference Rooms (Konferans Odaları) View
 */
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-users-rectangle" style="color: var(--primary);"></i> <?php echo t('conferences.title', 'Konferans Odaları (ConfBridge)'); ?>
            <span class="badge badge-secondary" style="font-size: 11px; margin-left: 8px;"><?php echo count($conferences); ?></span>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('confHelpBox')" title="<?php echo t('common.module_guide', 'Modül Rehberi'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('conferences', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateConfModal()" title="<?php echo t('conferences.new_conf_btn', 'Yeni Konferans Odası'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="confHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('conferences.help_title', 'Konferans Odaları Nasıl Çalışır?'); ?></h4>
        <p><?php echo t('conferences.help_body', 'Konferans odaları, dahili ve harici arayanların aynı anda bağlanarak toplu görüşme yapmasını sağlar.'); ?></p>
        <ul>
            <li><strong><?php echo t('conferences.help_pins', 'Kullanıcı ve Yönetici PIN:'); ?></strong> <?php echo t('conferences.help_pins_desc', 'PIN tanımlanırsa arayanlardan PIN istenir. Yönetici PIN ile girenler lider yetkisi kazanır.'); ?></li>
            <li><strong><?php echo t('conferences.help_wait_leader', 'Lideri Bekle:'); ?></strong> <?php echo t('conferences.help_wait_leader_desc', 'Aktif edilirse, bir yönetici odaya girene kadar katılımcılar bekleme müziği dinler, görüşme lider gelince başlar.'); ?></li>
            <li><strong><?php echo t('conferences.help_live_ctrl', 'Canlı Denetim:'); ?></strong> <?php echo t('conferences.help_live_ctrl_desc', 'Aktif katılımcıları canlı izleyebilir, istediklerinizi sessize alabilir (Mute) veya odadan atabilirsiniz (Kick).'); ?></li>
        </ul>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success" style="margin: 15px 20px 0 20px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" style="margin: 15px 20px 0 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 70px;">Oda #</th>
                    <th>Oda Başlığı</th>
                    <th>PIN Kodları</th>
                    <th>Özellikler</th>
                    <th>Kapasite</th>
                    <th>Kayıt</th>
                    <th>Durum</th>
                    <th class="text-right">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($conferences)): ?>
                    <?php echo uiTableEmptyRow(8, t('conferences.empty', 'Henüz tanımlanmış bir konferans odası bulunmuyor.'), 'fa-users-rectangle'); ?>
                <?php else: ?>
                    <?php foreach ($conferences as $cf): ?>
                        <tr>
                            <td><span class="badge badge-info" style="font-size: 13px;"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($cf['room_number']); ?></span></td>
                            <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($cf['title']); ?></td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap; font-size: 11px;">
                                    <?php if (!empty($cf['user_pin'])): ?>
                                        <span class="badge badge-secondary" title="Katılımcı PIN"><i class="fas fa-key"></i> Katılımcı: <?php echo htmlspecialchars($cf['user_pin']); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="opacity: 0.7;">PIN'siz</span>
                                    <?php endif; ?>
                                    <?php if (!empty($cf['admin_pin'])): ?>
                                        <span class="badge badge-warning" title="Moderatör / Yönetici PIN"><i class="fas fa-crown"></i> Yönetici: <?php echo htmlspecialchars($cf['admin_pin']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                    <?php if ((int)$cf['wait_marked'] === 1): ?>
                                        <span class="badge badge-warning" title="Lider Gelene Kadar Bekletilir"><i class="fas fa-user-clock"></i> Lider Bekle</span>
                                    <?php endif; ?>
                                    <?php if ((int)$cf['mute_on_join'] === 1): ?>
                                        <span class="badge badge-secondary" title="Girişte Sessiz"><i class="fas fa-microphone-slash"></i> Sessiz Giriş</span>
                                    <?php endif; ?>
                                    <?php if ((int)$cf['announce_join_leave'] === 1): ?>
                                        <span class="badge badge-info" title="Giriş / Çıkış Sesli Anons Edilir"><i class="fas fa-volume-up"></i> Anons</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><span class="badge badge-secondary"><i class="fas fa-users"></i> Maks <?php echo (int)$cf['max_members']; ?></span></td>
                            <td>
                                <?php if ((int)$cf['record_conference'] === 1): ?>
                                    <span class="badge badge-danger" title="Oda Kaydediliyor"><i class="fas fa-microphone"></i> Kayıt</span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 11px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$cf['is_active'] === 1): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times"></i> Pasif</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div style="display: inline-flex; gap: 4px;">
                                    <button type="button" class="btn btn-info btn-sm" onclick="showLiveMembers('<?php echo htmlspecialchars($cf['room_number']); ?>', '<?php echo htmlspecialchars(addslashes($cf['title'])); ?>')" title="Canlı Katılımcılar">
                                        <i class="fas fa-users-viewfinder"></i> Canlı
                                    </button>
                                    <?php if (hasModulePermission('conferences', 'edit')): ?>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditConfModal(<?php echo json_encode($cf); ?>)' title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Bu konferans odasını silmek istediğinizden emin misiniz?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="delete_conference" value="1">
                                            <input type="hidden" name="conference_id" value="<?php echo $cf['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Sil">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Canlı Katılımcılar -->
<div class="modal-overlay" id="liveMembersModal">
    <div class="modal-card" style="max-width: 640px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="liveMembersTitle"><i class="fas fa-users-viewfinder" style="color: var(--primary);"></i> Canlı Konferans Katılımcıları</h3>
            <button class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('liveMembersModal')" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="liveMembersBody" style="min-height: 120px;">
                <div class="text-center text-muted" style="padding: 30px;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i><br><br>Katılımcılar yükleniyor...
                </div>
            </div>
            <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="refreshLiveMembers()">
                    <i class="fas fa-sync-alt"></i> Yenile
                </button>
                <button type="button" class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('liveMembersModal')">Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Konferans Odası Ekle / Düzenle -->
<div class="modal-overlay" id="confModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="confModalTitle"><i class="fas fa-users-rectangle" style="color: var(--primary);"></i> Konferans Odası</h3>
            <button class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('confModal')" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_conference" value="1">
                <input type="hidden" name="id" id="modal_conf_id" value="0">

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Oda Dahili Numarası</label>
                        <input type="text" name="room_number" id="modal_conf_number" class="form-control" placeholder="ör: 6000" pattern="[0-9]{3,6}" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Konferans Başlığı</label>
                        <input type="text" name="title" id="modal_conf_title" class="form-control" placeholder="ör: Haftalık Koordinasyon Odası" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-key text-info"></i> Katılımcı PIN (Boş ise şifresiz)</label>
                        <input type="text" name="user_pin" id="modal_conf_user_pin" class="form-control" placeholder="ör: 1234" pattern="[0-9]*">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-crown text-warning"></i> Yönetici (Moderatör) PIN</label>
                        <input type="text" name="admin_pin" id="modal_conf_admin_pin" class="form-control" placeholder="ör: 9876" pattern="[0-9]*">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Maksimum Katılımcı</label>
                        <input type="number" name="max_members" id="modal_conf_max_members" class="form-control" value="50" min="2" max="500">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Bekleme Müziği Sınıfı</label>
                        <input type="text" name="music_on_hold" id="modal_conf_moh" class="form-control" value="default" placeholder="default">
                    </div>
                </div>

                <div style="background: var(--bg-input); padding: 12px; border-radius: 8px; margin-bottom: 16px;">
                    <div style="font-weight: 600; font-size: 13px; margin-bottom: 10px; color: var(--text-main);">Gelişmiş Seçenekler</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 12px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" name="wait_marked" id="modal_conf_wait_marked" value="1" style="accent-color: var(--primary);">
                            <span>Yönetici girmeden başlatma (Lider Bekle)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" name="end_marked" id="modal_conf_end_marked" value="1" style="accent-color: var(--primary);">
                            <span>Yönetici çıkınca odayı kapat</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" name="record_conference" id="modal_conf_record" value="1" style="accent-color: var(--primary);">
                            <span>Konferansı Ses Kaydı Yap</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" name="mute_on_join" id="modal_conf_mute_on_join" value="1" style="accent-color: var(--primary);">
                            <span>Katılımcılar sessizde girsin (Mute)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" name="announce_join_leave" id="modal_conf_announce_join" value="1" checked style="accent-color: var(--primary);">
                            <span>Giriş / Çıkışları anons et</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" name="announce_user_count" id="modal_conf_announce_count" value="1" checked style="accent-color: var(--primary);">
                            <span>Girişte katılımcı sayısını söyle</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_conf_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;">Oda Aktif</span>
                    </label>
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('confModal')", t('common.save', 'Kaydet'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script>
let currentActiveRoomNumber = '';

function openCreateConfModal() {
    document.getElementById('confModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Konferans Odası';
    document.getElementById('modal_conf_id').value = '0';
    document.getElementById('modal_conf_number').value = '';
    document.getElementById('modal_conf_title').value = '';
    document.getElementById('modal_conf_user_pin').value = '';
    document.getElementById('modal_conf_admin_pin').value = '';
    document.getElementById('modal_conf_max_members').value = '50';
    document.getElementById('modal_conf_moh').value = 'default';
    document.getElementById('modal_conf_wait_marked').checked = false;
    document.getElementById('modal_conf_end_marked').checked = false;
    document.getElementById('modal_conf_record').checked = false;
    document.getElementById('modal_conf_mute_on_join').checked = false;
    document.getElementById('modal_conf_announce_join').checked = true;
    document.getElementById('modal_conf_announce_count').checked = true;
    document.getElementById('modal_conf_active').checked = true;
    UIHelper.openOverlayModal('confModal');
}

function openEditConfModal(cf) {
    document.getElementById('confModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Oda Düzenle: ' + escapeHtml(cf.title || '');
    document.getElementById('modal_conf_id').value = cf.id || '0';
    document.getElementById('modal_conf_number').value = cf.room_number || '';
    document.getElementById('modal_conf_title').value = cf.title || '';
    document.getElementById('modal_conf_user_pin').value = cf.user_pin || '';
    document.getElementById('modal_conf_admin_pin').value = cf.admin_pin || '';
    document.getElementById('modal_conf_max_members').value = cf.max_members || 50;
    document.getElementById('modal_conf_moh').value = cf.music_on_hold || 'default';
    document.getElementById('modal_conf_wait_marked').checked = (cf.wait_marked == 1);
    document.getElementById('modal_conf_end_marked').checked = (cf.end_marked == 1);
    document.getElementById('modal_conf_record').checked = (cf.record_conference == 1);
    document.getElementById('modal_conf_mute_on_join').checked = (cf.mute_on_join == 1);
    document.getElementById('modal_conf_announce_join').checked = (cf.announce_join_leave == 1);
    document.getElementById('modal_conf_announce_count').checked = (cf.announce_user_count == 1);
    document.getElementById('modal_conf_active').checked = (cf.is_active == 1);
    UIHelper.openOverlayModal('confModal');
}

function showLiveMembers(roomNumber, roomTitle) {
    currentActiveRoomNumber = roomNumber;
    document.getElementById('liveMembersTitle').innerHTML = '<i class="fas fa-users-viewfinder" style="color: var(--primary);"></i> Oda ' + escapeHtml(roomNumber) + ' - ' + escapeHtml(roomTitle);
    UIHelper.openOverlayModal('liveMembersModal');
    refreshLiveMembers();
}

function refreshLiveMembers() {
    if (!currentActiveRoomNumber) return;
    const body = document.getElementById('liveMembersBody');
    body.innerHTML = '<div class="text-center text-muted" style="padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Katılımcılar sorgulanıyor...</div>';

    fetch('/api/conferences.php?action=members&room=' + encodeURIComponent(currentActiveRoomNumber))
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.members || data.members.length === 0) {
                body.innerHTML = '<div class="text-center text-muted" style="padding: 30px;"><i class="fas fa-coffee fa-2x" style="opacity: 0.5;"></i><br><br>Şu anda odada aktif katılımcı bulunmuyor.</div>';
                return;
            }

            let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';
            data.members.forEach(m => {
                html += '<div style="padding: 10px 14px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: space-between;">';
                html += '<div>';
                html += '<span style="font-weight: 700; color: var(--text-main); font-size: 13px;">' + escapeHtml(m.callerid || m.channel) + '</span>';
                if (m.is_admin) html += ' <span class="badge badge-warning" style="font-size: 10px;"><i class="fas fa-crown"></i> Yönetici</span>';
                if (m.is_muted) html += ' <span class="badge badge-danger" style="font-size: 10px;"><i class="fas fa-microphone-slash"></i> Sessizde</span>';
                html += '<div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">' + escapeHtml(m.channel) + '</div>';
                html += '</div>';
                
                html += '<div style="display: inline-flex; gap: 6px;">';
                if (m.is_muted) {
                    html += '<button type="button" class="btn btn-secondary btn-sm" onclick="toggleMuteMember(\'' + m.channel + '\', false)" title="Sesi Aç"><i class="fas fa-microphone"></i> Sesi Aç</button>';
                } else {
                    html += '<button type="button" class="btn btn-warning btn-sm" onclick="toggleMuteMember(\'' + m.channel + '\', true)" title="Sessize Al"><i class="fas fa-microphone-slash"></i> Sessize Al</button>';
                }
                html += '<button type="button" class="btn btn-danger btn-sm" onclick="kickMember(\'' + m.channel + '\')" title="Odadan At"><i class="fas fa-user-times"></i> At</button>';
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
            body.innerHTML = html;
        })
        .catch(() => {
            body.innerHTML = '<div class="alert alert-danger">Katılımcı bilgisi alınamadı.</div>';
        });
}

function kickMember(channel) {
    if (!confirm('Bu katılımcıyı odadan çıkarmak istediğinizden emin misiniz?')) return;
    const form = new FormData();
    form.append('csrf_token', window.CSRF_TOKEN);
    form.append('kick_member', '1');
    form.append('room_number', currentActiveRoomNumber);
    form.append('channel', channel);

    fetch('/conferences', { method: 'POST', body: form })
        .then(() => refreshLiveMembers())
        .catch(() => refreshLiveMembers());
}

function toggleMuteMember(channel, mute) {
    const form = new FormData();
    form.append('csrf_token', window.CSRF_TOKEN);
    form.append('mute_member', '1');
    form.append('room_number', currentActiveRoomNumber);
    form.append('channel', channel);
    form.append('mute_action', mute ? 'mute' : 'unmute');

    fetch('/conferences', { method: 'POST', body: form })
        .then(() => refreshLiveMembers())
        .catch(() => refreshLiveMembers());
}
</script>
