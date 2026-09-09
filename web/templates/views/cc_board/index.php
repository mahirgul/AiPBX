<div style="display: flex; flex-direction: column; gap: 20px;" id="cc-board-root">

    <!-- Üst Bar: Kuyruk/Tarih Filtresi & Tam Ekran -->
    <div class="card" style="padding: 16px 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(0, 242, 254, 0.15); display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 20px;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div>
                    <h2 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main);"><?php echo t('sidebar.item_cc_board_unified'); ?></h2>
                    <p style="font-size: 12px; color: var(--text-muted); margin: 2px 0 0 0;"><?php echo t('cc_board.subtitle'); ?></p>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <select id="board-queue-filter" class="form-control form-control-sm" style="width: 190px; font-weight: 600;" onchange="loadAllBoardData()">
                    <option value="ALL">🌐 <?php echo t('cc_board.all_my_queues'); ?> (<?php echo count($my_queues); ?>)</option>
                    <?php foreach ($my_queues as $q): ?>
                        <option value="<?php echo htmlspecialchars($q['queue_name']); ?>">
                            🎧 <?php echo htmlspecialchars($q['title'] ?: $q['queue_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="board-range-filter" class="form-control form-control-sm" style="width: 120px; font-weight: 600;" onchange="loadAllBoardData()">
                    <option value="today"><?php echo t('cc_board.today'); ?></option>
                    <option value="week"><?php echo t('cc_board.this_week'); ?></option>
                    <option value="month"><?php echo t('cc_board.this_month'); ?></option>
                </select>

                <div style="display: flex; align-items: center; gap: 6px; padding: 4px 10px; background: rgba(0, 0, 0, 0.04); border-radius: 6px; font-weight: 700;">
                    <i class="far fa-clock" style="color: var(--primary);"></i>
                    <span id="board-clock" style="font-family: monospace; font-size: 13px; color: var(--text-main);">--:--</span>
                    <span id="board-day" style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">-</span>
                </div>

                <button class="btn btn-secondary btn-sm" onclick="toggleBoardFullscreen()" title="<?php echo t('cc_board.fullscreen_tooltip'); ?>">
                    <i class="fas fa-expand" id="board-fullscreen-icon"></i>
                </button>
                <button class="btn btn-primary btn-sm" onclick="loadAllBoardData()" title="<?php echo t('cc_board.refresh_tooltip'); ?>">
                    <i class="fas fa-sync-alt" id="board-refresh-icon"></i> <?php echo t('cc_board.refresh'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- 1. BÖLÜM: Kompakt Canlı Pano ve KPI Metrikleri -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px;" class="cc-board-kpi-grid">
        <!-- 1. Bekleyen Çağrı -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid var(--danger); display: flex; flex-direction: column; justify-content: space-between;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <span class="cc-board-label" style="color: var(--danger);"><?php echo t('cc_board.waiting_call'); ?></span>
                <i class="fas fa-phone-volume" style="color: var(--danger); opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 4px;">
                <div class="cc-board-val-compact" style="color: var(--danger);" id="board-waiting-calls">0</div>
                <span style="font-size: 11px; color: var(--text-muted); margin-left: auto;">Maks: <span id="board-max-wait" style="font-family: monospace; font-weight: 700; color: var(--text-main);">00:00</span></span>
            </div>
        </div>

        <!-- 2. Cevaplanan / Toplam Çağrı -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid var(--secondary); display: flex; flex-direction: column; justify-content: space-between;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <span class="cc-board-label"><?php echo t('cc_board.answered_call'); ?> / <?php echo t('cc_board.total_call'); ?></span>
                <i class="fas fa-phone-alt" style="color: var(--secondary); opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 4px;">
                <span class="cc-board-val-compact" style="color: var(--secondary);" id="board-answered-calls">0</span>
                <span style="font-size: 13px; color: var(--text-muted); font-weight: 700;">/ <span id="board-total-calls">0</span></span>
                <span class="badge badge-success" style="font-size: 11px; padding: 2px 6px; margin-left: auto;">%<span id="board-answered-rate">0</span></span>
            </div>
        </div>

        <!-- 3. Kaçan Çağrılar (Cevapsız & Terk) -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid var(--warning); display: flex; flex-direction: column; justify-content: space-between;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <span class="cc-board-label"><?php echo t('cc_board.missed_call'); ?> / <?php echo t('cc_board.abandoned_call'); ?></span>
                <i class="fas fa-phone-slash" style="color: var(--warning); opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 4px;">
                <span class="cc-board-val-compact" style="color: var(--danger);" id="board-missed-calls">0</span>
                <span style="font-size: 13px; color: var(--text-muted); font-weight: 700;">/</span>
                <span class="cc-board-val-compact" style="color: var(--warning);" id="board-abandoned-calls">0</span>
                <span style="font-size: 11px; color: var(--text-muted); margin-left: auto;">Terk: %<span id="board-abandon-rate">0</span></span>
                <span id="board-missed-rate" style="display: none;">0</span>
            </div>
        </div>

        <!-- 4. Süreler (Ortalama Bekleme / Konuşma) -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid var(--primary); display: flex; flex-direction: column; justify-content: space-between;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <span class="cc-board-label"><?php echo t('cc_board.avg_wait'); ?> / <?php echo t('cc_board.avg_talk'); ?></span>
                <i class="fas fa-stopwatch" style="color: var(--primary); opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 4px;">
                <span class="cc-board-val-compact" style="font-family: monospace; color: var(--text-main);" id="board-avg-wait">00:00</span>
                <span style="font-size: 13px; color: var(--text-muted);">/</span>
                <span style="font-size: 15px; font-weight: 700; font-family: monospace; color: var(--text-main);" id="board-avg-talk">00:00</span>
            </div>
        </div>

        <!-- 5. Temsilciler & SLA -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid #6366f1; display: flex; flex-direction: column; justify-content: space-between;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <span class="cc-board-label"><?php echo t('cc_board.agents_logged_in'); ?> & SLA</span>
                <i class="fas fa-headset" style="color: #6366f1; opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; justify-content: space-between; margin-top: 4px;">
                <div>
                    <span class="cc-board-val-compact" style="color: var(--success);" id="board-agents-available">0</span>
                    <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">/ <span id="board-agents-logged-in">0</span></span>
                    <span style="font-size: 11px; color: var(--danger); font-weight: 600; margin-left: 4px;">(<span id="board-active-calls">0</span> aktif)</span>
                    <span id="board-agents-total" style="display: none;">0</span>
                </div>
                <div style="text-align: right;">
                    <span style="font-size: 15px; font-weight: 800; color: #6366f1;"><span id="board-sla-pct">0</span>%</span>
                    <span style="font-size: 10px; color: var(--text-muted); display: block;"><span id="board-sla-threshold">20</span>sn</span>
                </div>
            </div>
        </div>
    </div>
    <!-- 2. BÖLÜM: Canlı Operasyon Tabloları (Bekleyen Çağrılar & Temsilci İzleme) -->
    <div style="display: grid; grid-template-columns: 1fr 1.1fr; gap: 16px;" class="cc-board-ops-grid">
        <!-- Sol Panel: Canlı Bekleyen Çağrılar (Interactive Pickup) -->
        <div class="card" style="display: flex; flex-direction: column;">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding: 14px 16px;">
                <div class="card-title" style="display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 14px;">
                    <i class="fas fa-phone-volume" style="color: var(--danger);"></i>
                    <span><?php echo t('cc_supervisor.waiting_calls_live'); ?></span>
                </div>
                <span class="badge badge-danger" id="waiting-badge">0 Çağrı Bekliyor</span>
            </div>
            <div class="table-responsive" style="flex: 1; max-height: 420px; overflow-y: auto;">
                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th><?php echo t('cc_supervisor.col_caller'); ?></th>
                            <th><?php echo t('cc_supervisor.col_queue'); ?></th>
                            <th><?php echo t('cc_supervisor.col_wait_time'); ?></th>
                            <th class="text-right"><?php echo t('cc_supervisor.col_action'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sup-waiting-calls-tbody">
                        <tr>
                            <td colspan="4" class="text-center text-muted" style="padding: 24px;">
                                <i class="fas fa-check-circle" style="color: var(--success); margin-right: 6px;"></i> <?php echo t('cc_supervisor.no_waiting_calls'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sağ Panel: Canlı Temsilci Durumları & Mola Takibi -->
        <div class="card" style="display: flex; flex-direction: column;">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding: 14px 16px;">
                <div class="card-title" style="display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 14px;">
                    <i class="fas fa-users-cog" style="color: var(--primary);"></i>
                    <span><?php echo t('cc_supervisor.agent_status_title'); ?></span>
                </div>
                <span class="badge badge-info" id="agents-count-badge">0 Temsilci</span>
            </div>
            <div class="table-responsive" style="flex: 1; max-height: 420px; overflow-y: auto;">
                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th><?php echo t('cc_supervisor.col_extension'); ?></th>
                            <th><?php echo t('cc_supervisor.col_agent_name'); ?></th>
                            <th><?php echo t('cc_supervisor.col_status'); ?></th>
                            <th><?php echo t('cc_supervisor.col_pause_detail'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sup-agents-tbody">
                        <tr>
                            <td colspan="4" class="text-center text-muted" style="padding: 24px;">
                                <?php echo t('cc_supervisor.loading_agents'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.cc-board-val-compact {
    font-size: 1.7rem;
    font-weight: 800;
    line-height: 1.1;
}
.cc-board-label {
    font-size: 0.72rem;
    color: var(--text-muted);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
@media (max-width: 992px) {
    .cc-board-kpi-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
    .cc-board-ops-grid {
        grid-template-columns: 1fr !important;
    }
}
@media (max-width: 576px) {
    .cc-board-kpi-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
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

    // 1. Pano KPI İstatistikleri
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

    // 2. Canlı Bekleyen Çağrılar
    const p2 = fetch('/api/cc.php?action=get_live_calls')
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                renderWaitingCalls(data.calls || [], queueFilter);
            }
        })
        .catch(() => {});

    // 3. Canlı Temsilci Durumları
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
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted" style="padding: 24px;"><i class="fas fa-check-circle" style="color: var(--success); margin-right: 6px;"></i> Kuyruklarda bekleyen çağrı bulunmuyor.</td></tr>';
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
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted" style="padding: 24px;"><i class="fas fa-info-circle" style="margin-right: 6px;"></i> Tanımlı kuyruk temsilcisi bulunamadı.</td></tr>';
        return;
    }

    let html = '';
    filtered.forEach(a => {
        let statusBadge = '<span class="badge badge-secondary">Çevrimdışı</span>';
        let detail = '-';

        if (a.is_in_call) {
            statusBadge = '<span class="badge badge-danger"><i class="fas fa-phone"></i> Görüşmede</span>';
            detail = a.call_partner ? `<span style="color: var(--danger); font-weight: 600;">${escapeHtml(a.call_partner)}</span>` : 'Görüşmede';
        } else if (a.is_paused) {
            statusBadge = '<span class="badge badge-warning"><i class="fas fa-pause"></i> Molada</span>';
            detail = `<span style="color: var(--warning); font-weight: 600;">${escapeHtml(a.pause_reason || 'Mola')}</span>`;
            if (a.pause_duration) detail += ` (${escapeHtml(a.pause_duration)})`;
        } else if (a.is_logged_in) {
            statusBadge = '<span class="badge badge-success"><i class="fas fa-check"></i> Boşta</span>';
            detail = '<span style="color: var(--success); font-weight: 500;">Çağrı Bekliyor</span>';
        }

        html += `
            <tr>
                <td style="font-weight: 700; font-family: monospace; color: var(--text-main); font-size: 13px;">${escapeHtml(a.extension)}</td>
                <td style="font-weight: 600;">${escapeHtml(a.full_name || a.extension)}</td>
                <td>${statusBadge}</td>
                <td style="font-size: 12px;">${detail}</td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
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
</script>
