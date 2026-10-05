/* Page script of templates/views/cc_board/index.php */

const CAN_SPY = window.CC_BOARD_CAN_SPY;
var boardUnifiedTimer = null;
var boardUnifiedClockTimer = null;

function formatBoardSeconds(secs) {
    secs = Math.max(0, parseInt(secs, 10) || 0);
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

function loadAllBoardData() {
    const qFilterEl = document.getElementById('board-queue-filter');
    const rFilterEl = document.getElementById('board-range-filter');
    if (!qFilterEl || !rFilterEl) return;

    const queueFilter = qFilterEl.value;
    const rangeFilter = rFilterEl.value;
    const icon = document.getElementById('board-refresh-icon');
    if (icon) icon.classList.add('fa-spin');

    // 1. Board KPI statistics
    const p1 = UIHelper.ccGet('get_board_stats', '&queue=' + encodeURIComponent(queueFilter) + '&range=' + encodeURIComponent(rangeFilter))
        .then(data => {
            if (!data || !data.success) return;

            const waitEl = document.getElementById('board-waiting-calls');
            if (waitEl) {
                waitEl.innerText = data.waiting_calls;
                waitEl.style.color = (parseInt(data.waiting_calls, 10) > 0) ? 'var(--danger)' : 'var(--text-main)';
            }
            const avgWaitEl = document.getElementById('board-avg-wait');
            if (avgWaitEl) avgWaitEl.innerText = formatBoardSeconds(data.avg_wait);
            const avgTalkEl = document.getElementById('board-avg-talk');
            if (avgTalkEl) avgTalkEl.innerText = formatBoardSeconds(data.avg_talk);
            const maxWaitEl = document.getElementById('board-max-wait');
            if (maxWaitEl) maxWaitEl.innerText = formatBoardSeconds(data.max_wait);

            const missedEl = document.getElementById('board-missed-calls');
            if (missedEl) missedEl.innerText = data.missed_calls;
            const abanEl = document.getElementById('board-abandoned-calls');
            if (abanEl) abanEl.innerText = data.abandoned_calls;
            const ansEl = document.getElementById('board-answered-calls');
            if (ansEl) ansEl.innerText = data.answered_calls;
            const totEl = document.getElementById('board-total-calls');
            if (totEl) totEl.innerText = data.total_calls;

            const agLogEl = document.getElementById('board-agents-logged-in');
            if (agLogEl) agLogEl.innerText = data.agents_logged_in;
            const agTotEl = document.getElementById('board-agents-total');
            if (agTotEl) agTotEl.innerText = data.agents_total;
            const agAvailEl = document.getElementById('board-agents-available');
            if (agAvailEl) agAvailEl.innerText = data.agents_available;
            const actCallEl = document.getElementById('board-active-calls');
            if (actCallEl) actCallEl.innerText = data.active_calls;

            const slaPctEl = document.getElementById('board-sla-pct');
            if (slaPctEl) slaPctEl.innerText = data.sla_pct;
            const slaThreshEl = document.getElementById('board-sla-threshold');
            if (slaThreshEl) slaThreshEl.innerText = data.sla_threshold;

            const ansRateEl = document.getElementById('board-answered-rate');
            if (ansRateEl) ansRateEl.innerText = data.answered_rate;
            const misRateEl = document.getElementById('board-missed-rate');
            if (misRateEl) misRateEl.innerText = data.missed_rate;
            const abRateEl = document.getElementById('board-abandon-rate');
            if (abRateEl) abRateEl.innerText = data.abandon_rate;
        })
        .catch(() => {});

    // 2. Live waiting calls
    const p2 = fetch('/api/cc.php?action=get_live_calls')
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                renderWaitingCalls(data.calls || [], queueFilter);
            }
        })
        .catch(() => {});

    // 3. Live agent states
    const p3 = fetch('/api/cc.php?action=get_supervisor_agents')
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                renderAgentsStatus(data.agents || [], queueFilter);
            }
        })
        .catch(() => {});

    Promise.allSettled([p1, p2, p3]).finally(() => {
        if (icon) icon.classList.remove('fa-spin');
    });
}

function renderWaitingCalls(calls, queueFilter) {
    const tbody = document.getElementById('sup-waiting-calls-tbody');
    const badge = document.getElementById('waiting-badge');
    if (!tbody) return;

    let filtered = calls;
    if (queueFilter && queueFilter !== 'ALL') {
        filtered = calls.filter(c => c.queue === queueFilter);
    }

    if (badge) badge.innerText = filtered.length + ' Çağrı Bekliyor';

    if (!filtered || filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted u-p-24"><i class="fas fa-check-circle" style="color: var(--success); margin-right: 6px;"></i> Kuyruklarda bekleyen çağrı bulunmuyor.</td></tr>';
        return;
    }

    let html = '';
    filtered.forEach(c => {
        html += `
            <tr>
                <td style="font-weight: 700; color: var(--text-main); font-size: 13px;">
                    <i class="fas fa-phone-alt" style="color: var(--danger); margin-right: 6px;"></i> ${escapeHtml(c.caller)}
                </td>
                <td><span class="badge badge-info">${escapeHtml(c.queue_title || c.queue)}</span></td>
                <td style="font-weight: 700; color: var(--warning); font-family: monospace;">${escapeHtml(c.wait_time)} sn</td>
                <td class="text-right">
                    <button class="btn btn-success btn-sm" onclick="pickupCall('${escapeHtml(c.channel)}')" style="font-weight: 600; padding: 4px 8px; font-size: 12px;">
                        <i class="fas fa-hand-holding-medical"></i> Çağrıyı Al
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function renderAgentsStatus(agents, queueFilter) {
    const tbody = document.getElementById('sup-agents-tbody');
    const countBadge = document.getElementById('agents-count-badge');
    if (!tbody) return;

    let filtered = agents;
    if (queueFilter && queueFilter !== 'ALL') {
        filtered = agents.filter(a => (a.queues || []).includes(queueFilter));
    }

    if (countBadge) countBadge.innerText = filtered.length + ' Temsilci';

    if (!filtered || filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="' + (CAN_SPY ? 5 : 4) + '" class="text-center text-muted u-p-24"><i class="fas fa-info-circle" style="margin-right: 6px;"></i> Tanımlı kuyruk temsilcisi bulunamadı.</td></tr>';
        return;
    }

    let html = '';
    filtered.forEach(a => {
        let statusBadge = '<span class="badge badge-secondary">Çevrimdışı</span>';
        let detail = '-';

        if (a.is_in_call) {
            statusBadge = '<span class="badge badge-danger"><i class="fas fa-phone"></i> Görüşmede</span>';
            const partner = a.connected_number || a.call_partner;
            const dur = a.duration_formatted ? ` <span class="badge badge-secondary" style="font-family: monospace; font-size: 10px; margin-left: 4px;">${escapeHtml(a.duration_formatted)}</span>` : '';
            detail = partner ? `<span style="color: var(--danger); font-weight: 700;"><i class="fas fa-phone-volume"></i> ${escapeHtml(partner)}</span>${dur}` : 'Görüşmede';
        } else if (a.is_ringing) {
            // The phone is ringing: the call has not started yet, no listen buttons.
            statusBadge = '<span class="badge badge-info"><i class="fas fa-bell"></i> Çalıyor</span>';
            detail = '<span style="color: var(--info, #0ea5e9); font-weight: 600;">Çağrı çalıyor</span>';
        } else if (a.is_paused) {
            statusBadge = '<span class="badge badge-warning"><i class="fas fa-pause"></i> Molada</span>';
            detail = `<span class="u-warning u-fw-600">${escapeHtml(a.pause_reason || 'Mola')}</span>`;
            if (a.pause_duration) detail += ` (${escapeHtml(a.pause_duration)})`;
        } else if (a.is_logged_in) {
            statusBadge = '<span class="badge badge-success"><i class="fas fa-check"></i> Boşta</span>';
            detail = '<span style="color: var(--success); font-weight: 500;">Çağrı Bekliyor</span>';
        }

        let actions = '';
        if (CAN_SPY && a.is_in_call) {
            const ext = escapeHtml(a.extension);
            actions = `
                <div style="display: inline-flex; gap: 4px;">
                    <button class="btn btn-outline-info btn-sm" onclick="spyCall('${ext}', 'spy')" title="Gizli dinle (yalnızca siz duyarsınız)" style="padding: 2px 8px; font-size: 11px;"><i class="fas fa-headphones"></i> Dinle</button>
                    <button class="btn btn-outline-warning btn-sm" onclick="spyCall('${ext}', 'whisper')" title="Fısılda (yalnızca temsilci duyar)" style="padding: 2px 8px; font-size: 11px;"><i class="fas fa-comment-dots"></i> Fısılda</button>
                    <button class="btn btn-outline-danger btn-sm" onclick="spyCall('${ext}', 'barge')" title="Dahil ol (iki taraf da duyar)" style="padding: 2px 8px; font-size: 11px;"><i class="fas fa-users"></i> Dahil Ol</button>
                </div>`;
        }

        html += `
            <tr>
                <td style="font-weight: 700; font-family: monospace; color: var(--text-main); font-size: 13px;">${escapeHtml(a.extension)}</td>
                <td class="u-fw-600">${escapeHtml(a.full_name || a.extension)}</td>
                <td style="white-space: nowrap;">${statusBadge}</td>
                <td class="u-fs-12">${detail}</td>
                ${CAN_SPY ? `<td class="text-right" style="white-space: nowrap;">${actions}</td>` : ''}
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function spyCall(targetExt, mode) {
    const modeNames = { spy: 'Gizli Dinleme', whisper: 'Fısıldama', barge: 'Araya Girme' };
    if (!confirm(`${targetExt} numaralı temsilcinin görüşmesine ${modeNames[mode] || 'Dinleme'} modunda bağlanılsın mı?\nTelefonunuz çaldırılacak.`)) return;

    fetch('/api/cc.php?action=spy_call', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '') + '&target_ext=' + encodeURIComponent(targetExt) + '&mode=' + encodeURIComponent(mode)
    })
    .then(res => res.json())
    .then(data => {
        if (window.showFooterToast) window.showFooterToast(data.success ? data.message : (data.error || 'İşlem başlatılamadı'), data.success ? 'success' : 'error');
    })
    .catch(e => { if (window.showFooterToast) window.showFooterToast('İstek gönderilemedi: ' + e, 'error'); });
}

function pickupCall(channel) {
    if (!confirm('Bu çağrıyı kendi telefonunuza çekmek/almak istediğinize emin misiniz?')) return;

    fetch('/api/cc.php?action=pickup_call', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '') + '&channel=' + encodeURIComponent(channel)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (window.showFooterToast) window.showFooterToast(data.message, 'success');
            loadAllBoardData();
        } else {
            if (window.showFooterToast) window.showFooterToast(data.error || 'Çağrı alınamadı', 'error');
        }
    });
}

function updateBoardClock() {
    const now = new Date();
    const clockEl = document.getElementById('board-clock');
    const dayEl = document.getElementById('board-day');
    if (clockEl) {
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        clockEl.innerText = hh + ':' + mm;
    }
    if (dayEl) {
        const days = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];
        dayEl.innerText = days[now.getDay()];
    }
}

function toggleBoardFullscreen() {
    const icon = document.getElementById('board-fullscreen-icon');
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().then(() => {
            if (icon) icon.className = 'fas fa-compress';
        }).catch(() => {});
    } else {
        document.exitFullscreen().then(() => {
            if (icon) icon.className = 'fas fa-expand';
        }).catch(() => {});
    }
}

function initUnifiedBoardPage() {
    if (!document.getElementById('cc-board-root')) return;

    loadAllBoardData();
    updateBoardClock();

    if (boardUnifiedTimer) clearInterval(boardUnifiedTimer);
    boardUnifiedTimer = setInterval(() => {
        if (document.getElementById('cc-board-root')) {
            loadAllBoardData();
        } else {
            clearInterval(boardUnifiedTimer);
        }
    }, 5000);

    if (boardUnifiedClockTimer) clearInterval(boardUnifiedClockTimer);
    boardUnifiedClockTimer = setInterval(() => {
        if (document.getElementById('cc-board-root')) {
            updateBoardClock();
        } else {
            clearInterval(boardUnifiedClockTimer);
        }
    }, 15000);
}

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    initUnifiedBoardPage();
} else {
    document.addEventListener('DOMContentLoaded', initUnifiedBoardPage);
}
document.addEventListener('spa:pageLoaded', initUnifiedBoardPage);
