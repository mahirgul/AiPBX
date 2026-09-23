<div style="display: flex; flex-direction: column; gap: 20px;">

    <!-- Top Header Bar with Multi-Queue Filter & Auto Refresh Controls -->
    <div class="card page-header-card" style="margin-bottom: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center; color: var(--warning); font-size: 20px;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div>
                    <h2 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main);"><?php echo t('cc_supervisor.title'); ?></h2>
                    <p style="font-size: 12px; color: var(--text-muted); margin: 2px 0 0 0;"><?php echo t('cc_supervisor.subtitle'); ?></p>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 12px;">
                <select id="supervisor-queue-filter" class="form-control form-control-sm" style="width: 200px; font-weight: 600;" onchange="loadSupervisorData()">
                    <option value="ALL">🌐 <?php echo t('cc_supervisor.all_my_queues'); ?> (<?php echo count($my_queues); ?>)</option>
                    <?php foreach ($my_queues as $q): ?>
                        <option value="<?php echo htmlspecialchars($q['queue_name']); ?>">
                            🎧 <?php echo htmlspecialchars($q['title'] ?: $q['queue_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="btn btn-primary btn-sm" onclick="loadSupervisorData()" title="<?php echo t('cc_supervisor.refresh_tooltip'); ?>">
                    <i class="fas fa-sync-alt" id="sup-refresh-icon"></i> <?php echo t('cc_supervisor.refresh'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Live Metric Overview Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
        <div class="card" style="padding: 16px; border-left: 4px solid var(--danger);">
            <div style="font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;"><?php echo t('cc_supervisor.stat_waiting'); ?></div>
            <div style="font-size: 28px; font-weight: 800; color: var(--danger); margin-top: 6px;" id="stat-waiting-count">0</div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;"><?php echo t('cc_supervisor.stat_waiting_desc'); ?></div>
        </div>

        <div class="card" style="padding: 16px; border-left: 4px solid var(--success);">
            <div style="font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;"><?php echo t('cc_supervisor.stat_active_agents'); ?></div>
            <div style="font-size: 28px; font-weight: 800; color: var(--success); margin-top: 6px;" id="stat-active-agents">0</div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;"><?php echo t('cc_supervisor.stat_active_agents_desc'); ?></div>
        </div>

        <div class="card" style="padding: 16px; border-left: 4px solid var(--warning);">
            <div style="font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;"><?php echo t('cc_supervisor.stat_paused_agents'); ?></div>
            <div style="font-size: 28px; font-weight: 800; color: var(--warning); margin-top: 6px;" id="stat-paused-agents">0</div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;"><?php echo t('cc_supervisor.stat_paused_agents_desc'); ?></div>
        </div>

        <div class="card" style="padding: 16px; border-left: 4px solid var(--info);">
            <div style="font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;"><?php echo t('cc_supervisor.stat_today_answered'); ?></div>
            <div style="font-size: 28px; font-weight: 800; color: var(--primary); margin-top: 6px;" id="stat-today-answered">0</div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;"><?php echo t('cc_supervisor.stat_today_answered_desc'); ?></div>
        </div>
    </div>

    <!-- Live Waiting Calls Table (Interactive Pickup) -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-phone-volume" style="color: var(--danger);"></i> <?php echo t('cc_supervisor.waiting_calls_live'); ?>
            </div>
            <span class="badge badge-danger" id="waiting-badge">0 Çağrı Bekliyor</span>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('cc_supervisor.col_caller'); ?></th>
                        <th><?php echo t('cc_supervisor.col_queue'); ?></th>
                        <th><?php echo t('cc_supervisor.col_wait_time'); ?></th>
                        <th><?php echo t('cc_supervisor.col_status'); ?></th>
                        <th class="text-right"><?php echo t('cc_supervisor.col_action'); ?></th>
                    </tr>
                </thead>
                <tbody id="sup-waiting-calls-tbody">
                    <tr>
                        <td colspan="5" class="text-center text-muted" style="padding: 24px;"><?php echo t('cc_supervisor.no_waiting_calls'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Live Agent Status Table -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-users-cog" style="color: var(--primary);"></i> <?php echo t('cc_supervisor.agent_status_title'); ?>
            </div>
            <span class="badge badge-info" id="agents-count-badge">0 Temsilci</span>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('cc_supervisor.col_extension'); ?></th>
                        <th><?php echo t('cc_supervisor.col_agent_name'); ?></th>
                        <th><?php echo t('cc_supervisor.col_active_queues'); ?></th>
                        <th><?php echo t('cc_supervisor.col_status'); ?></th>
                        <th><?php echo t('cc_supervisor.col_pause_detail'); ?></th>
                    </tr>
                </thead>
                <tbody id="sup-agents-tbody">
                    <tr>
                        <td colspan="5" class="text-center text-muted" style="padding: 24px;"><?php echo t('cc_supervisor.loading_agents'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
// NOT: SPA ile tekrar çalıştırıldığında top-level `let` redeclaration
// SyntaxError verir (bkz. agent_ui.js'teki aynı not) — bu, "Yükleniyor..."
// yazısının başka sayfaya gidip geri dönünce takılı kalmasının sebebiydi
// (hata script'in tamamının çalışmasını durduruyordu). `var` kullanılır.
var supervisorTimer = null;

function loadSupervisorData() {
    const queueFilter = document.getElementById('supervisor-queue-filter').value;
    const icon = document.getElementById('sup-refresh-icon');
    if (icon) icon.classList.add('fa-spin');

    fetch('/api/cc.php?action=get_live_calls')
        .then(res => res.json())
        .then(data => {
            if (icon) icon.classList.remove('fa-spin');
            if (!data.success) return;

            renderWaitingCalls(data.calls || [], queueFilter);
        })
        .catch(e => { if (icon) icon.classList.remove('fa-spin'); });

    // Fetch live agents & queues status
    fetch('/api/cc.php?action=get_queues')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            fetchAgentsStatus(queueFilter);
        });
}

function renderWaitingCalls(calls, queueFilter) {
    const tbody = document.getElementById('sup-waiting-calls-tbody');
    const badge = document.getElementById('waiting-badge');
    const statCount = document.getElementById('stat-waiting-count');

    let filtered = calls;
    if (queueFilter && queueFilter !== 'ALL') {
        filtered = calls.filter(c => c.queue === queueFilter);
    }

    if (badge) badge.innerText = filtered.length + ' Çağrı Bekliyor';
    if (statCount) statCount.innerText = filtered.length;

    if (!filtered || filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted" style="padding: 24px;"><i class="fas fa-check-circle" style="color: var(--success); margin-right: 6px;"></i> Bekleyen çağrı bulunmuyor.</td></tr>';
        return;
    }

    let html = '';
    filtered.forEach(c => {
        html += `
            <tr>
                <td style="font-weight: 700; color: var(--text-main); font-size: 14px;">
                    <i class="fas fa-phone-alt" style="color: var(--danger); margin-right: 6px;"></i> ${escapeHtml(c.caller)}
                </td>
                <td><span class="badge badge-info">${escapeHtml(c.queue_title || c.queue)}</span></td>
                <td style="font-weight: 700; color: var(--warning);">${escapeHtml(c.wait_time)} sn</td>
                <td><span class="badge badge-danger">Kuyrukta Çalıyor</span></td>
                <td class="text-right">
                    <button class="btn btn-success btn-sm" onclick="pickupCall('${escapeHtml(c.channel)}')" style="font-weight: 600;">
                        <i class="fas fa-hand-holding-medical"></i> Çağrıyı Al
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function fetchAgentsStatus(queueFilter) {
    fetch('/api/cc.php?action=get_supervisor_agents')
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('sup-agents-tbody');
            const countBadge = document.getElementById('agents-count-badge');
            if (!tbody) return;

            let pausedCount = 0;
            let activeCount = 0;
            let html = '';

            let agents = data.agents || [];
            if (queueFilter && queueFilter !== 'ALL') {
                agents = agents.filter(a => a.queue_name === queueFilter);
            }

            if (countBadge) countBadge.innerText = agents.length + ' Temsilci';

            agents.forEach(a => {
                if (a.in_queue && a.status_key !== 'OFFLINE') activeCount++;
                if (a.is_paused) pausedCount++;

                let statusBadge = '<span class="badge badge-secondary"><i class="fas fa-power-off"></i> Çevrimdışı (Bağlı Değil)</span>';
                let detailText = '<span class="text-muted">Telefon Oturumu Kapalı</span>';

                if (a.status_key === 'READY') {
                    statusBadge = '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Boşta / Hazır</span>';
                    detailText = '<span style="color: var(--success); font-weight: 600;">Çağrı Bekliyor</span>';
                } else if (a.status_key === 'BUSY') {
                    statusBadge = '<span class="badge badge-danger"><i class="fas fa-phone-alt"></i> Görüşmede</span>';
                    detailText = '<span style="color: var(--danger); font-weight: 600;">Çağrı Devam Ediyor</span>';
                } else if (a.status_key === 'PAUSED') {
                    statusBadge = '<span class="badge badge-warning"><i class="fas fa-coffee"></i> Molada</span>';
                    detailText = '<span style="color: var(--warning); font-weight: 600;">Aktif Mola Kaydı Var</span>';
                }

                html += `
                    <tr>
                        <td style="font-weight: 700; color: var(--primary);">PJSIP/${escapeHtml(a.extension)}</td>
                        <td style="font-weight: 700; color: var(--text-main);">${escapeHtml(a.full_name)}</td>
                        <td><span class="badge badge-info">${escapeHtml(a.queue_title || a.queue_name)}</span></td>
                        <td>${statusBadge}</td>
                        <td>${detailText}</td>
                    </tr>
                `;
            });

            if (html) {
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted" style="padding: 24px;"><i class="fas fa-info-circle" style="margin-right: 6px;"></i> Tanımlı kuyruk temsilcisi bulunamadı.</td></tr>';
            }

            document.getElementById('stat-active-agents').innerText = activeCount;
            document.getElementById('stat-paused-agents').innerText = pausedCount;
        });
}

function pickupCall(channel) {
    if (!confirm('Bu çağrıyı telefonunuza çekmek/almak istediğinize emin misiniz?')) return;

    fetch('/api/cc.php?action=pickup_call', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '') + '&channel=' + encodeURIComponent(channel)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (window.notify) window.notify.success(data.message);
            loadSupervisorData();
        } else {
            if (window.notify) window.notify.error(data.error || 'Çağrı alınamadı');
        }
    });
}

// escapeHtml is provided by ui_helper.js (loaded globally via header.php)

function initSupervisorPage() {
    if (!document.getElementById('supervisor-queue-filter')) return;

    loadSupervisorData();

    if (supervisorTimer) clearInterval(supervisorTimer);
    supervisorTimer = setInterval(() => {
        if (document.getElementById('supervisor-queue-filter')) {
            loadSupervisorData();
        } else {
            clearInterval(supervisorTimer);
        }
    }, 5000);
}

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    initSupervisorPage();
} else {
    document.addEventListener('DOMContentLoaded', initSupervisorPage);
}
document.addEventListener('spa:pageLoaded', initSupervisorPage);
</script>
