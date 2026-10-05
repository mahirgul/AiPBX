/* Page script of templates/views/conferences/index.php */

let currentActiveRoomNumber = '';

function openCreateConfModal() {
    document.getElementById('confModalTitle').innerHTML = '<i class="fas fa-plus-circle u-primary"></i> Yeni Konferans Odası';
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
    document.getElementById('confModalTitle').innerHTML = '<i class="fas fa-edit u-primary"></i> Oda Düzenle: ' + escapeHtml(cf.title || '');
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
    document.getElementById('liveMembersTitle').innerHTML = '<i class="fas fa-users-viewfinder u-primary"></i> Oda ' + escapeHtml(roomNumber) + ' - ' + escapeHtml(roomTitle);
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
                if (m.is_admin) html += ' <span class="badge badge-warning u-fs-10"><i class="fas fa-crown"></i> Yönetici</span>';
                if (m.is_muted) html += ' <span class="badge badge-danger u-fs-10"><i class="fas fa-microphone-slash"></i> Sessizde</span>';
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
