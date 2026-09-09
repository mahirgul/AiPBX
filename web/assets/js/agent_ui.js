/**
 * Dedicated Agent UI Controller Module for /cc-agent
 * NOT: SPA ile tekrar çalıştırıldığında top-level `let` redeclaration SyntaxError
 * verir; bu yüzden `var` kullanılır (tekrar tanımlanabilir).
 */
var agentTimerInterval = null;
var agentAutoLoginInterval = null;
var _loadAgentQueuesInFlight = false;
var _loadLiveCallsInFlight = false;

function loadAgentQueues() {
    const container = document.getElementById('agent-queues-list');
    if (!container) return;
    // 5sn'lik polling döngüsü önceki isteğin bitip bitmediğini kontrol etmiyordu —
    // yavaş bir ağda yanıtlar sırayla dönmezse eski veri yeni veriyi ezebiliyordu
    // (düşük etkili ama gerçek bir race condition, 2026-08-21 denetiminde bulundu).
    if (_loadAgentQueuesInFlight) return;
    _loadAgentQueuesInFlight = true;

    UIHelper.ccGet('get_queues')
        .then(data => {
            if (!data.success || !data.queues) {
                container.innerHTML = '<div class="text-muted" style="padding:4px; font-size:12px;">Kuyruk verisi yok.</div>';
                return;
            }

            let html = '';

            data.queues.forEach(q => {
                if (!q.assigned) return; // Only show queues assigned to this agent

                // Üyelik (in_queue) ile cihaz durumu (device_offline) ayrı gösterilir.
                // F5 sonrası WebRTC kaydı kısa süre düşse bile temsilci kuyruğun ÜYESİ kalır.
                const statusTag = !q.in_queue
                    ? '<span class="badge badge-secondary">⭕ Pasif</span>'
                    : (q.is_paused
                        ? '<span class="badge badge-warning">Molada</span>'
                        : (q.device_offline
                            ? '<span class="badge badge-info">⚪ Cihaz Çevrimdışı</span>'
                            : '<span class="badge badge-success">🟢 Aktif</span>'));

                // queue_name şu an QueueService'te [a-zA-Z0-9_-] ile kısıtlanıyor
                // (istismar edilemez) ama onclick içindeki JS-string bağlamı için
                // doğru araç escapeHtml değil escapeJsAttr'dır — savunma derinliği
                // için burada da tutarlı kullanılıyor (2026-08-21 denetiminde bulundu).
                const safeQueueName = escapeJsAttr(q.queue_name);
                const btnAction = q.in_queue
                    ? `<button class="btn btn-danger btn-xs" onclick="toggleQueueStatus('${safeQueueName}', 0)" title="Kuyruktan Çık"><i class="fas fa-sign-out-alt"></i> Çık</button>`
                    : `<button class="btn btn-success btn-xs" onclick="toggleQueueStatus('${safeQueueName}', 1)" title="Kuyruğa Gir"><i class="fas fa-sign-in-alt"></i> Gir</button>`;

                html += `
                    <div style="display: flex; align-items: center; gap: 8px; padding: 4px 8px; background: var(--bg-input); border-radius: 6px; border: 1px solid var(--border-color); min-width: 180px; font-size: 11px;">
                        <div style="display: flex; align-items: center; gap: 6px; overflow: hidden; white-space: nowrap; flex: 1;">
                            <div style="font-weight: 700; color: var(--text-main); font-size: 11px; text-overflow: ellipsis; overflow: hidden;" title="${escapeHtml(q.title || q.queue_name)} (${escapeHtml(q.queue_name)})">
                                🎧 ${escapeHtml(q.title || q.queue_name)}
                            </div>
                            ${statusTag}
                        </div>
                        <div style="flex-shrink: 0;">
                            ${btnAction}
                        </div>
                    </div>
                `;
            });

            if (!html) {
                container.innerHTML = '<div class="text-muted" style="padding:4px; font-size:12px;">Atanmış kuyruk yok.</div>';
            } else {
                container.innerHTML = html;
            }
        })
        .catch(err => {
            console.error("loadAgentQueues error:", err);
            container.innerHTML = '<div class="text-danger text-center" style="padding:8px; grid-column:1/-1;">Kuyruk verisi yüklenemedi.</div>';
        })
        .finally(() => { _loadAgentQueuesInFlight = false; });
}

function toggleQueueStatus(qName, doLogin) {
    UIHelper.ccPost('toggle_queue', { queue_name: qName, login: doLogin })
    .then(data => {
        if (data.success) {
            if (window.notify) window.notify.success(data.message || (doLogin ? 'Kuyruğa girildi' : 'Kuyruktan çıkıldı'));
            loadAgentQueues();
        } else {
            if (window.notify) window.notify.error(data.error || 'Kuyruk işlemi başarısız');
        }
    })
    .catch(e => {
        if (window.notify) window.notify.error('Kuyruk isteği başarısız!');
    });
}

function loadLiveCalls() {
    if (_loadLiveCallsInFlight) return;
    _loadLiveCallsInFlight = true;

    UIHelper.ccGet('get_live_calls')
        .then(data => {
            if (!data.success) return;

            // Render waiting calls
            const wTbody = document.getElementById('waiting-calls-tbody');
            const wBadge = document.getElementById('waiting-count-badge');
            const waiting = data.calls || data.waiting_calls || [];

            if (wBadge) wBadge.innerText = waiting.length;

            if (wTbody) {
                if (waiting.length === 0) {
                    wTbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 14px; font-size: 12px;"><i class="fas fa-check-circle" style="color: var(--success); margin-right: 6px;"></i> Bekleyen çağrı yok</td></tr>';
                } else {
                    let wHtml = '';
                    waiting.forEach(c => {
                        const callerNum = c.caller || c.caller_num || 'Bilinmeyen';
                        const queueTitle = c.queue_title || c.queue_name || c.queue || 'Kuyruk';
                        wHtml += `
                            <tr>
                                <td><span class="badge badge-info">${escapeHtml(queueTitle)}</span></td>
                                <td style="font-weight: 700; color: var(--text-main);">${escapeHtml(callerNum)}</td>
                                <td style="font-weight: 700; color: var(--warning);">${escapeHtml(c.wait_time)} sn</td>
                                <td style="text-align: right;">
                                    <button class="btn btn-success btn-xs" onclick="pickupCall('${escapeJsAttr(c.channel)}')" style="font-weight: 600;">
                                        <i class="fas fa-phone-alt"></i> Al
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    wTbody.innerHTML = wHtml;
                }
            }

            // Render active calls
            const aTbody = document.getElementById('active-calls-tbody');
            const aBadge = document.getElementById('active-count-badge');
            const active = data.active_calls || [];

            if (aBadge) aBadge.innerText = active.length;

            if (aTbody) {
                if (active.length === 0) {
                    aTbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 14px; font-size: 12px;">Aktif çağrı yok</td></tr>';
                } else {
                    let aHtml = '';
                    active.forEach(ac => {
                        const callerNum = ac.caller || ac.caller_num || 'Bilinmeyen';
                        // Not butonu sadece bu ekranın sahibi olan temsilcinin kendi çağrısında gösterilir
                        // (aktif çağrılar tablosu sistemdeki tüm görüşmeleri listeler).
                        const isMine = window.AGENT_EXT && String(ac.exten || '') === String(window.AGENT_EXT);
                        const noteBtn = isMine
                            ? `<button class="btn btn-secondary btn-xs" onclick="openNoteModal(null, true)" title="Çağrı Notu (görüşme sırasında)"><i class="fas fa-sticky-note"></i></button>`
                            : '<span class="text-muted" style="font-size: 11px;">-</span>';
                        aHtml += `
                            <tr>
                                <td style="font-weight: 700;">${escapeHtml(ac.channel)}</td>
                                <td>${escapeHtml(callerNum)}</td>
                                <td>${escapeHtml(ac.duration)} sn</td>
                                <td><span class="badge badge-success">Görüşülüyor</span></td>
                                <td style="text-align: right;">${noteBtn}</td>
                            </tr>
                        `;
                    });
                    aTbody.innerHTML = aHtml;
                }
            }
        })
        .catch(err => {
            console.error("loadLiveCalls error:", err);
        })
        .finally(() => { _loadLiveCallsInFlight = false; });
}

function pickupCall(channel) {
    UIHelper.ccPost('pickup_call', { channel: channel })
    .then(data => {
        if (data.success) {
            if (window.notify) window.notify.success(data.message || 'Çağrı telefonunuza aktarıldı');
            loadLiveCalls();
        } else {
            if (window.notify) window.notify.error(data.error || 'Çağrı alınamadı');
        }
    });
}

function loadCdrs() {
    const tbody = document.getElementById('agent-cdr-table');
    if (!tbody) return;

    UIHelper.ccGet('my_cdrs')
        .then(data => {
            if (!data.success || !data.cdrs || data.cdrs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">Çağrı kaydı bulunmuyor.</td></tr>';
                return;
            }

            let html = '';
            data.cdrs.forEach(cdr => {
                const isAnswered = cdr.status === 'ANSWERED';
                const statusBadge = isAnswered
                    ? '<span class="badge badge-success">Cevaplandı</span>'
                    : '<span class="badge badge-danger">Cevapsız</span>';

                let audioBtn = '<span class="text-muted" style="font-size: 11px;">Yok</span>';
                if (cdr.has_recording && cdr.can_listen) {
                    audioBtn = `<button class="btn btn-secondary btn-xs" onclick="playAudio('${escapeJsAttr(cdr.audio_url)}')"><i class="fas fa-play"></i> Dinle</button>`;
                }

                let noteBtn = '<span class="text-muted" style="font-size: 11px;">-</span>';
                if (cdr.call_id) {
                    noteBtn = `<button class="btn btn-secondary btn-xs" onclick="openNoteModal('${escapeJsAttr(cdr.call_id)}')" title="Çağrı Notu"><i class="fas fa-sticky-note"></i></button>`;
                }

                html += `
                    <tr>
                        <td style="font-size: 12px;">${escapeHtml(cdr.start_time)}</td>
                        <td style="font-weight: 700; color: var(--text-main);">${escapeHtml(cdr.caller_num)}</td>
                        <td>${escapeHtml(cdr.duration)} sn</td>
                        <td>${statusBadge}</td>
                        <td style="text-align: right;">${audioBtn}</td>
                        <td style="text-align: right;">${noteBtn}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        })
        .catch(e => {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">CDR verileri yüklenemedi.</td></tr>';
        });
}

function playAudio(url) {
    window.open(url, '_blank');
}

// --- Çağrı Notu (callcenter_notes) ---
// isPending=true: çağrı henüz aktif, CDR/call_id yok -> temsilciye bağlı "bekleyen" not
// olarak sunucu tarafında saklanır, çağrı bitip CDR oluşunca otomatik ilişkilendirilir.
var noteModalPending = false;

function openNoteModal(callId, isPending) {
    const modal = document.getElementById('callNoteModal');
    if (!modal) return;

    noteModalPending = !!isPending;
    document.getElementById('note_call_id').value = isPending ? '' : callId;
    document.getElementById('note_customer_name').value = '';
    document.getElementById('note_phone').value = '';
    document.getElementById('note_disposition').value = '';
    document.getElementById('note_notes').value = '';

    const notePromise = isPending
        ? UIHelper.ccGet('get_pending_note')
        : UIHelper.ccGet('get_call_note', '&call_id=' + encodeURIComponent(callId));

    notePromise
        .then(data => {
            if (data.success && data.note) {
                document.getElementById('note_customer_name').value = data.note.customer_name || '';
                document.getElementById('note_phone').value = data.note.phone || '';
                document.getElementById('note_disposition').value = data.note.disposition || '';
                document.getElementById('note_notes').value = data.note.notes || '';
            }
        })
        .finally(() => {
            modal.style.display = 'flex';
            modal.classList.add('active');
        });
}

function closeNoteModal() {
    UIHelper.closeOverlayModal('callNoteModal');
}

function submitCallNote() {
    const callId = document.getElementById('note_call_id').value;
    if (!callId && !noteModalPending) return;

    UIHelper.ccPost('save_call_note', {
        call_id: callId,
        customer_name: document.getElementById('note_customer_name').value,
        phone: document.getElementById('note_phone').value,
        disposition: document.getElementById('note_disposition').value,
        notes: document.getElementById('note_notes').value
    })
        .then(data => {
            if (data.success) {
                if (window.notify) window.notify.success(data.message || 'Not kaydedildi');
                closeNoteModal();
            } else {
                if (window.notify) window.notify.error(data.error || 'Not kaydedilemedi');
            }
        })
        .catch(() => {
            if (window.notify) window.notify.error('Not kaydedilemedi');
        });
}

// escapeHtml is provided by ui_helper.js (loaded globally via header.php)


function initAgentPage() {
    if (!document.getElementById('agent-queues-list')) return;

    // cc_auto_queue_login ayarı açıksa, atanmış tüm kuyruklara otomatik giriş yap
    UIHelper.ccPost('auto_login', { last_queues: '[]' }).finally(() => {
        loadAgentQueues();
        loadLiveCalls();
        loadCdrs();
    });

    // F5 sonrası WebRTC kaydının yeniden kurulması saniyeler alabilir;
    // ilk 3 saniyede sık yenileme ile doğru duruma hızlı yakınsa
    setTimeout(function () {
        if (document.getElementById('agent-queues-list')) {
            loadAgentQueues();
            loadLiveCalls();
        }
    }, 1500);
    setTimeout(function () {
        if (document.getElementById('agent-queues-list')) {
            loadAgentQueues();
            loadLiveCalls();
        }
    }, 3000);

    if (agentTimerInterval) clearInterval(agentTimerInterval);
    agentTimerInterval = setInterval(() => {
        if (document.getElementById('agent-queues-list')) {
            loadAgentQueues();
            loadLiveCalls();
        } else {
            clearInterval(agentTimerInterval);
        }
    }, 5000);

    // Asterisk yeniden başlatılırsa (Dashboard'daki "Servisi Yeniden Başlat"
    // veya sunucu reboot'u) dinamik kuyruk üyeliği sıfırlanır — sayfa açık
    // kalan bir temsilci sekmesi bunu sayfayı yenilemeden fark edemez, o ana
    // kadar arayan hattı boş bulur (2026-08-23 incelemesinde bulunan kör
    // nokta). auto_login zaten kendi başına idempotent (DB'deki atama
    // listesine göre ekler/çıkarır, mola durumunu korur) — periyodik
    // tekrarlamak sekme açık kalsa bile en geç 1 dakika içinde kendi kendini
    // onarır, sayfa yenilemesine bağımlı kalmaz.
    if (agentAutoLoginInterval) clearInterval(agentAutoLoginInterval);
    agentAutoLoginInterval = setInterval(() => {
        if (document.getElementById('agent-queues-list')) {
            UIHelper.ccPost('auto_login', { last_queues: '[]' });
        } else {
            clearInterval(agentAutoLoginInterval);
        }
    }, 60000);
}

if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(initAgentPage, 50);
} else {
    document.addEventListener('DOMContentLoaded', initAgentPage);
}
document.addEventListener('spa:pageLoaded', initAgentPage);
