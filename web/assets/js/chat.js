/* Page script of templates/views/chat/index.php */

// Chat WebSocket & page state manager (singleton)
window._chatWsState = window._chatWsState || {
    ws: null,
    reconnectTimer: null,
    intentionalClose: false
};

// Page state variables (kept on window so repeated SPA loads do not collide)
window.currentConvId = null;
window.currentConv = null;
window.currentTargetExt = null;
window.conversations = window.conversations || [];
window.contacts = window.contacts || [];
window.currentTab = 'convs';
window.pendingUpload = null;
window.newGroupAvatarUrl = '';
window.typingTimeout = null;
window.isTypingSent = false;
window.audioCtx = null;
window.ws = null;

function getChatWs() {
    return window._chatWsState ? window._chatWsState.ws : null;
}

function initChatPage() {
    if (!document.getElementById('chat-ws-status-badge')) {
        return; // do nothing outside the chat page
    }
    initChatWebSocket();
    loadConversations();
    loadContacts();
}

// SPA page-change listener (registered once)
if (!window._chatSpaHookRegistered) {
    window._chatSpaHookRegistered = true;
    document.addEventListener('spa:pageLoaded', function(e) {
        if (window.location.pathname.startsWith('/chat')) {
            initChatPage();
        } else {
            // Leaving the chat page: close the open socket and clear the timers
            cleanupChatWebSocket();
        }
    });
}

// First load (normal browser refresh)
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    initChatPage();
} else {
    document.addEventListener('DOMContentLoaded', initChatPage, { once: true });
}

// 1. WebSocket connection and lifecycle
function initChatWebSocket() {
    const badge = document.getElementById('chat-ws-status-badge');
    if (!badge) {
        return; // do nothing when the page has no badge
    }

    const state = window._chatWsState;

    // Already connected or connecting: do not open a duplicate socket!
    if (state.ws && (state.ws.readyState === WebSocket.OPEN || state.ws.readyState === WebSocket.CONNECTING)) {
        ws = state.ws;
        if (state.ws.readyState === WebSocket.OPEN) {
            badge.innerHTML = '<i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: #10b981;"></i> Bağlandı';
            badge.style.color = '#10b981';
        }
        return;
    }

    // Clear the pending reconnect timer
    if (state.reconnectTimer) {
        clearTimeout(state.reconnectTimer);
        state.reconnectTimer = null;
    }

    state.intentionalClose = false;

    // Reset the previous socket's listeners so its close does not trigger a reconnect
    if (state.ws) {
        try {
            state.ws.onopen = null;
            state.ws.onclose = null;
            state.ws.onerror = null;
            state.ws.onmessage = null;
            state.ws.close();
        } catch(e) {}
        state.ws = null;
    }

    const wsProto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const wsUrl = `${wsProto}//${window.location.host}/chat/ws?token=${encodeURIComponent(window.CHAT_TOKEN)}&active=${document.hidden ? 0 : 1}`;

    badge.innerHTML = '<i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: var(--warning);"></i> Bağlanıyor...';
    badge.style.color = 'var(--text-muted)';

    try {
        state.ws = new WebSocket(wsUrl);
        ws = state.ws;
    } catch(err) {
        console.error('[Chat WS] WebSocket oluşturma hatası:', err);
        badge.innerHTML = '<i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: var(--danger);"></i> Bağlantı hatası';
        badge.style.color = 'var(--danger)';
        scheduleChatReconnect();
        return;
    }

    state.ws.onopen = function() {
        ws = state.ws;
        sendChatActive();
        if (state.reconnectTimer) {
            clearTimeout(state.reconnectTimer);
            state.reconnectTimer = null;
        }
        const b = document.getElementById('chat-ws-status-badge');
        if (b) {
            b.innerHTML = '<i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: #10b981;"></i> Bağlandı';
            b.style.color = '#10b981';
        }
    };

    state.ws.onclose = function(ev) {
        ws = null;
        if (state.intentionalClose || !document.getElementById('chat-ws-status-badge')) {
            return;
        }
        const b = document.getElementById('chat-ws-status-badge');
        if (b) {
            b.innerHTML = '<i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: var(--danger);"></i> Yeniden bağlanıyor...';
            b.style.color = 'var(--danger)';
        }
        scheduleChatReconnect();
    };

    state.ws.onerror = function(err) {
        console.warn('[Chat WS] Soket uyarısı/hatası:', err);
    };

    state.ws.onmessage = function(e) {
        try {
            const lines = (e.data || '').split('\n');
            for (const line of lines) {
                if (line.trim()) {
                    const data = JSON.parse(line);
                    handleWsEvent(data);
                }
            }
        } catch (ex) {
            console.error('[Chat WS] JSON ayrıştırma hatası:', ex);
        }
    };
}

function scheduleChatReconnect() {
    const state = window._chatWsState;
    if (!state) return;
    if (state.reconnectTimer) {
        clearTimeout(state.reconnectTimer);
    }
    state.reconnectTimer = setTimeout(() => {
        state.reconnectTimer = null;
        if (document.getElementById('chat-ws-status-badge')) {
            initChatWebSocket();
        }
    }, 3000);
}

function cleanupChatWebSocket() {
    const state = window._chatWsState;
    if (!state) return;
    state.intentionalClose = true;
    if (state.reconnectTimer) {
        clearTimeout(state.reconnectTimer);
        state.reconnectTimer = null;
    }
    if (state.ws) {
        try {
            state.ws.onopen = null;
            state.ws.onclose = null;
            state.ws.onerror = null;
            state.ws.onmessage = null;
            state.ws.close();
        } catch(e) {}
        state.ws = null;
        ws = null;
    }
}

// 2. WebSocket Olay Dinleyicisi
function handleWsEvent(evt) {
    if (evt.event === 'new_message') {
        const msg = evt.data;
        // Show it if it belongs to the open conversation
        if (currentConvId && msg.conversation_id === currentConvId) {
            appendMessageToUI(msg);
            scrollToBottom();
            // Mark as read
            markCurrentConversationRead(msg.id);
        } else {
            // Another conversation: play the sound and bump the unread count
            playNotificationSound();
        }
        // Update / refetch the conversation list
        loadConversations();

    } else if (evt.event === 'presence') {
        if (evt.last_seen) chatLastSeen[evt.extension] = evt.last_seen;
        updateUserPresence(evt.extension, evt.is_online);

    } else if (evt.event === 'presence_snapshot') {
        // The snapshot is the full list: everyone not in it is offline.
        Object.assign(chatLastSeen, evt.last_seen || {});
        const online = new Set(Array.isArray(evt.extensions) ? evt.extensions : []);
        document.querySelectorAll('[id^="contact-dot-"]').forEach(function (dot) {
            const ext = dot.id.replace('contact-dot-', '');
            if (!online.has(ext)) updateUserPresence(ext, false);
        });
        if (currentTargetExt && !online.has(currentTargetExt)) updateUserPresence(currentTargetExt, false);
        online.forEach(function (ext) { updateUserPresence(ext, true); });

    } else if (evt.event === 'typing') {
        if (currentConvId && evt.conversation_id === currentConvId) {
            showTypingIndicator(evt.from_name, evt.is_typing);
        }

    } else if (evt.event === 'receipts') {
        if (currentConvId && evt.conversation_id === currentConvId) {
            applyReceipts(evt.read_upto, evt.delivered_upto);
        }

    } else if (evt.event === 'messages_read') {
        if (currentConvId && evt.conversation_id === currentConvId) {
            markUiMessagesAsRead(evt.last_message_id);
        }

    } else if (evt.event === 'group_created') {
        loadConversations();

    } else if (evt.event === 'group_updated') {
        const data = evt.data || {};
        if (currentConv && currentConv.id === data.conversation_id) {
            currentConv.title = data.title;
            currentConv.avatar_url = data.avatar_url;
            currentConv.description = data.description;
            document.getElementById('active-target-name').textContent = data.title;
            if (data.avatar_url) {
                document.getElementById('active-target-avatar').innerHTML = `<img src="${escapeHtml(sanitizeAttachmentUrl(data.avatar_url))}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
            }
        }
        loadConversations();

    } else if (evt.event === 'group_member_added' || evt.event === 'group_role_updated') {
        const data = evt.data || {};
        if (currentConv && currentConv.id === data.conversation_id) {
            fetchGroupDetails(data.conversation_id);
        }
        loadConversations();

    } else if (evt.event === 'group_member_removed') {
        const data = evt.data || {};
        if (data.extension === window.MY_EXT) {
            if (currentConv && currentConv.id === data.conversation_id) {
                closeActiveConversation();
                alert('Bu gruptan çıkarıldınız veya ayrıldınız.');
            }
        } else if (currentConv && currentConv.id === data.conversation_id) {
            fetchGroupDetails(data.conversation_id);
        }
        loadConversations();

    } else if (evt.event === 'group_deleted') {
        const data = evt.data || {};
        if (currentConv && currentConv.id === data.conversation_id) {
            closeActiveConversation();
            alert('Bu grup yönetici tarafından silindi.');
        }
        loadConversations();
    }
}

// 3. Fetch conversations and the directory
async function loadConversations() {
    try {
        const res = await fetch('/chat/api/conversations', {
            headers: { 'Authorization': 'Bearer ' + window.CHAT_TOKEN }
        });
        const json = await res.json();
        if (json.success) {
            conversations = json.conversations || [];
            renderConversationsList();
        }
    } catch (e) {
        console.error('Konuşmalar çekilemedi:', e);
    } finally {
        document.getElementById('chat-list-loading').style.display = 'none';
    }
}

async function loadContacts() {
    try {
        const res = await fetch('/chat/api/contacts', {
            headers: { 'Authorization': 'Bearer ' + window.CHAT_TOKEN }
        });
        const json = await res.json();
        if (json.success) {
            contacts = json.contacts || [];
            renderContactsList();
        }
    } catch (e) {
        console.error('Kişiler çekilemedi:', e);
    }
}

// 4. List render functions
function renderConversationsList() {
    const listEl = document.getElementById('chat-convs-list');
    listEl.innerHTML = '';

    if (conversations.length === 0) {
        listEl.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px;">Henüz bir sohbetiniz yok.<br>Rehberden bir dahili seçip mesajlaşabilir veya Yeni Grup oluşturabilirsiniz.</div>';
        return;
    }

    conversations.forEach(c => {
        const isSelected = currentConvId === c.id;
        const isGroup = c.type === 'group';
        const item = document.createElement('div');
        item.style.cssText = `
            display: flex; align-items: center; gap: 10px; padding: 10px 12px;
            border-radius: 10px; cursor: pointer; transition: background 0.15s;
            background: ${isSelected ? 'rgba(0, 242, 254, 0.12)' : 'transparent'};
        `;
        item.onmouseenter = () => { if (!isSelected) item.style.background = 'var(--bg-main)'; };
        item.onmouseleave = () => { if (!isSelected) item.style.background = 'transparent'; };
        item.onclick = () => openConversation(c);

        let avatarHtml = '';
        if (isGroup) {
            if (c.avatar_url) {
                avatarHtml = `<img src="${escapeHtml(sanitizeAttachmentUrl(c.avatar_url))}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">`;
            } else {
                avatarHtml = `
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;">
                        <i class="fas fa-users"></i>
                    </div>
                `;
            }
        } else {
            const initial = (c.target_name || c.target_ext || 'U').charAt(0).toUpperCase();
            const onlineColor = c.target_online ? '#10b981' : '#9ca3af';
            avatarHtml = `
                <div style="position: relative; width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                    ${initial}
                    <span style="position: absolute; bottom: 0; right: 0; width: 10px; height: 10px; border-radius: 50%; background: ${onlineColor}; border: 2px solid var(--bg-card);"></span>
                </div>
            `;
        }

        const titleText = isGroup ? (c.title || 'Grup') : (c.target_name || c.target_ext);
        const subtitleText = c.last_message_text || (isGroup ? `${c.member_count || 0} üye` : 'Sohbet başlatıldı');

        item.innerHTML = `
            ${avatarHtml}
            <div style="flex: 1; min-width: 0;">
                <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <span style="font-size: 13.5px; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 6px;">
                        ${isGroup ? '<i class="fas fa-users" style="font-size: 11px; color: #6366f1;"></i> ' : ''}${escapeHtml(titleText)}
                    </span>
                    <span style="font-size: 11px; color: var(--text-muted); margin-left: 6px; flex-shrink: 0;">
                        ${c.last_message_at ? formatTime(c.last_message_at) : ''}
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2px;">
                    <span style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; min-width: 0;">
                        ${escapeHtml(subtitleText)}
                    </span>
                    ${c.unread_count > 0 ? `<span class="badge" style="background: var(--primary); color: #fff; font-size: 10.5px; padding: 2px 6px; border-radius: 10px; font-weight: 700; margin-left: 6px;">${c.unread_count}</span>` : ''}
                </div>
            </div>
        `;
        listEl.appendChild(item);
    });
}

function renderContactsList() {
    const listEl = document.getElementById('chat-contacts-list');
    listEl.innerHTML = '';

    contacts.forEach(u => {
        const item = document.createElement('div');
        item.style.cssText = `
            display: flex; align-items: center; gap: 10px; padding: 10px 12px;
            border-radius: 10px; cursor: pointer; transition: background 0.15s;
        `;
        item.onmouseenter = () => item.style.background = 'var(--bg-main)';
        item.onmouseleave = () => item.style.background = 'transparent';
        item.onclick = () => startDirectChatWith(u.extension, u.full_name);

        const initial = (u.full_name || u.extension).charAt(0).toUpperCase();
        const onlineColor = u.is_online ? '#10b981' : '#9ca3af';

        item.innerHTML = `
            <div style="position: relative; width: 38px; height: 38px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                ${escapeHtml(initial)}
                <span id="contact-dot-${u.extension}" style="position: absolute; bottom: 0; right: 0; width: 10px; height: 10px; border-radius: 50%; background: ${onlineColor}; border: 2px solid var(--bg-card);"></span>
            </div>
            <div style="flex: 1; min-width: 0;">
                <div style="font-size: 13.5px; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    ${escapeHtml(u.full_name)}
                </div>
                <div style="font-size: 12px; color: var(--text-muted); display: flex; gap: 6px;">
                    <span>#${escapeHtml(u.extension)}</span>
                    <span>•</span>
                    <span>${escapeHtml(u.role)}</span>
                </div>
            </div>
        `;
        listEl.appendChild(item);
    });
}

// 5. Tab switch (Chats / Directory)
function switchChatTab(tab) {
    currentTab = tab;
    const btnConvs = document.getElementById('tab-btn-convs');
    const btnContacts = document.getElementById('tab-btn-contacts');
    const listConvs = document.getElementById('chat-convs-list');
    const listContacts = document.getElementById('chat-contacts-list');

    if (tab === 'convs') {
        btnConvs.style.background = 'var(--bg-card)';
        btnConvs.style.color = 'var(--text-main)';
        btnConvs.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
        btnContacts.style.background = 'transparent';
        btnContacts.style.color = 'var(--text-muted)';
        btnContacts.style.boxShadow = 'none';
        listConvs.style.display = 'flex';
        listContacts.style.display = 'none';
    } else {
        btnContacts.style.background = 'var(--bg-card)';
        btnContacts.style.color = 'var(--text-main)';
        btnContacts.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
        btnConvs.style.background = 'transparent';
        btnConvs.style.color = 'var(--text-muted)';
        btnConvs.style.boxShadow = 'none';
        listContacts.style.display = 'flex';
        listConvs.style.display = 'none';
    }
}

// 6. Start a direct chat
async function startDirectChatWith(targetExt, targetName) {
    try {
        const res = await fetch('/chat/api/conversations/direct', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ target_extension: targetExt })
        });
        const json = await res.json();
        if (json.success && json.conversation) {
            switchChatTab('convs');
            openConversation(json.conversation);
            loadConversations();
        }
    } catch (e) {
        alert('Sohbet başlatılamadı: ' + e.message);
    }
}

// 7. Open a conversation
async function openConversation(conv, pushHistory = true) {
    currentConvId = conv.id;
    currentReceipts = { read: 0, delivered: 0 };
    currentConv = conv;
    currentTargetExt = conv.target_ext || '';
    const isGroup = conv.type === 'group';

    // Switch to the conversation view on mobile
    const cardWrapper = document.querySelector('.chat-card-wrapper');
    if (cardWrapper) {
        cardWrapper.classList.add('is-chat-open');
    }
    const chatContainer = document.querySelector('.chat-container');
    if (chatContainer) {
        chatContainer.classList.add('is-chat-open');
    }
    if (pushHistory && window.innerWidth <= 768) {
        try {
            window.history.pushState({ chatActive: true, convId: conv.id }, '');
        } catch (e) {}
    }

    document.getElementById('chat-empty-state').style.display = 'none';
    document.getElementById('chat-active-pane').style.display = 'flex';

    const avatarBox = document.getElementById('active-target-avatar');
    const nameEl = document.getElementById('active-target-name');
    const extEl = document.getElementById('active-target-ext');
    const statusDot = document.getElementById('active-target-status-dot');
    const statusText = document.getElementById('active-target-status-text');
    const callBtn = document.getElementById('active-target-call-btn');
    const groupInfoBtn = document.getElementById('active-group-info-btn');

    if (isGroup) {
        if (conv.avatar_url) {
            avatarBox.style.background = 'transparent';
            avatarBox.innerHTML = `<img src="${escapeHtml(sanitizeAttachmentUrl(conv.avatar_url))}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
        } else {
            avatarBox.innerHTML = `<i class="fas fa-users" style="font-size: 18px;"></i>`;
            avatarBox.style.background = 'linear-gradient(135deg, #6366f1, #8b5cf6)';
        }
        statusDot.style.display = 'none';
        nameEl.textContent = conv.title || 'Grup';
        extEl.textContent = `${conv.member_count || 0} üye`;
        extEl.style.background = 'rgba(99, 102, 241, 0.12)';
        extEl.style.color = '#6366f1';
        statusText.textContent = 'Grup bilgisi için tıklayın';
        statusText.style.color = 'var(--text-muted)';
        callBtn.style.display = 'none';
        groupInfoBtn.style.display = 'inline-flex';

        fetchGroupDetails(conv.id);
    } else {
        avatarBox.style.background = 'var(--primary)';
        avatarBox.innerHTML = `
            <span id="active-target-initial">${(conv.target_name || conv.target_ext || 'U').charAt(0).toUpperCase()}</span>
            <span id="active-target-status-dot" style="position: absolute; bottom: 0; right: 0; width: 11px; height: 11px; border-radius: 50%; background: ${conv.target_online ? '#10b981' : '#9ca3af'}; border: 2px solid var(--bg-card);"></span>
        `;
        statusDot.style.display = 'block';
        nameEl.textContent = conv.target_name || conv.target_ext;
        extEl.textContent = '#' + conv.target_ext;
        extEl.style.background = 'rgba(0,0,0,0.06)';
        extEl.style.color = 'var(--text-muted)';
        statusText.textContent = conv.target_online ? 'Çevrimiçi' : 'Çevrimdışı';
        statusText.style.color = conv.target_online ? '#10b981' : 'var(--text-muted)';
        callBtn.style.display = 'inline-flex';
        groupInfoBtn.style.display = 'none';
    }

    // Fetch the messages
    await loadMessages(conv.id);

    // Re-render the list (to update the selected background)
    renderConversationsList();

    // Focus the textarea on desktop (only > 768px, so the keyboard does not pop up and cover the screen on mobile)
    if (window.innerWidth > 768) {
        document.getElementById('chat-input-textarea').focus();
    }
}

// On mobile: close the active chat and go back to the list
function closeActiveChatMobile(popHistory = true) {
    const cardWrapper = document.querySelector('.chat-card-wrapper');
    if (cardWrapper) {
        cardWrapper.classList.remove('is-chat-open');
    }
    const chatContainer = document.querySelector('.chat-container');
    if (chatContainer) {
        chatContainer.classList.remove('is-chat-open');
    }
    currentConvId = null;
    currentConv = null;
    currentTargetExt = null;
    document.getElementById('chat-empty-state').style.display = 'flex';
    document.getElementById('chat-active-pane').style.display = 'none';
    renderConversationsList();

    if (popHistory && window.history.state && window.history.state.chatActive) {
        window.history.back();
    }
}

// Browser / Android hardware back-button listener
window.addEventListener('popstate', (e) => {
    const cardWrapper = document.querySelector('.chat-card-wrapper');
    if (cardWrapper && cardWrapper.classList.contains('is-chat-open')) {
        closeActiveChatMobile(false);
    }
});

async function loadMessages(convId) {
    const scrollEl = document.getElementById('chat-messages-scroll');
    scrollEl.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Mesajlar yükleniyor...</div>';

    try {
        const res = await fetch(`/chat/api/messages?conversation_id=${convId}&limit=50`, {
            headers: { 'Authorization': 'Bearer ' + window.CHAT_TOKEN }
        });
        const json = await res.json();
        scrollEl.innerHTML = '';
        if (json.success && json.messages) {
            if (json.messages.length === 0) {
                scrollEl.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-muted); font-size: 13px;">Bu sohbette henüz mesaj yok.<br>İlk mesajı siz gönderin!</div>';
            } else {
                json.messages.forEach(m => appendMessageToUI(m));
            }
            scrollToBottom();
        }
    } catch (e) {
        scrollEl.innerHTML = '<div style="text-align: center; color: var(--danger); padding: 20px;">Mesajlar yüklenirken hata oluştu.</div>';
    }
}

// 8. Append a message to the screen
// A hidden portal tab does not make the user look online.
function sendChatActive() {
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ action: 'set_active', active: !document.hidden }));
    }
}
if (!window.__chatVisibilityHooked) {
    window.__chatVisibilityHooked = true;
    document.addEventListener('visibilitychange', function () { sendChatActive(); });
}

function appendMessageToUI(msg) {
    const scrollEl = document.getElementById('chat-messages-scroll');

    // System message view
    if (msg.msg_type === 'system') {
        const sysRow = document.createElement('div');
        sysRow.id = `chat-msg-${msg.id}`;
        sysRow.style.cssText = `
            align-self: center; margin: 6px 0; padding: 4px 14px;
            background: rgba(0,0,0,0.05); color: var(--text-muted);
            font-size: 11.5px; border-radius: 12px; max-width: 85%;
            text-align: center; line-height: 1.4;
        `;
        sysRow.innerHTML = `<i class="fas fa-info-circle" style="margin-right: 4px;"></i> ${escapeHtml(msg.message)}`;
        scrollEl.appendChild(sysRow);
        return;
    }

    const isMe = msg.is_me || msg.sender_ext === window.MY_EXT;

    const msgRow = document.createElement('div');
    msgRow.id = `chat-msg-${msg.id}`;
    msgRow.style.cssText = `
        display: flex; flex-direction: column;
        align-items: ${isMe ? 'flex-end' : 'flex-start'};
        width: 100%;
    `;

    let contentHtml = '';
    if (msg.msg_type === 'image') {
        const safeUrl = sanitizeAttachmentUrl(msg.attachment_url);
        contentHtml = `
            <div style="cursor: pointer;" onclick="openSafeLightbox(this)" data-url="${escapeHtml(safeUrl)}">
                <img src="${escapeHtml(safeUrl)}" alt="Fotoğraf" style="max-width: min(260px, 75vw); max-height: 260px; border-radius: 8px; object-fit: cover; display: block; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            </div>
            ${msg.message ? `<div style="margin-top: 6px; font-size: 13.5px;">${escapeHtml(msg.message)}</div>` : ''}
        `;
    } else if (msg.msg_type === 'file') {
        const fileSizeStr = formatFileSize(msg.file_size);
        const safeUrl = sanitizeAttachmentUrl(msg.attachment_url);
        const safeName = escapeHtml(msg.file_name || 'Belge');
        contentHtml = `
            <div style="display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,0.05); padding: 8px 12px; border-radius: 8px;">
                <i class="fas fa-file-alt" style="font-size: 24px; color: var(--primary);"></i>
                <div style="overflow: hidden; max-width: min(180px, 50vw);">
                    <div style="font-size: 13px; font-weight: 600; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">${safeName}</div>
                    <div style="font-size: 11px; opacity: 0.8;">${fileSizeStr}</div>
                </div>
                <a href="${escapeHtml(safeUrl)}" download="${safeName}" target="_blank" style="margin-left: 6px; color: inherit; padding: 6px; font-size: 14px;">
                    <i class="fas fa-download"></i>
                </a>
            </div>
            ${msg.message ? `<div style="margin-top: 6px; font-size: 13.5px;">${escapeHtml(msg.message)}</div>` : ''}
        `;
    } else {
        contentHtml = `<div style="font-size: 13.5px; line-height: 1.45; word-break: break-word;">${escapeHtml(msg.message)}</div>`;
    }

    const bubbleBg = isMe ? 'var(--primary)' : 'var(--bg-card)';
    const bubbleColor = isMe ? '#ffffff' : 'var(--text-main)';
    const bubbleBorder = isMe ? 'none' : '1px solid var(--border-color)';

    const senderHeader = (currentConv && currentConv.type === 'group' && !isMe) ? `
        <div style="font-size: 11px; font-weight: 700; color: ${getSenderColor(msg.sender_ext)}; margin-bottom: 3px;">
            ${escapeHtml(msg.sender_name || ('#' + msg.sender_ext))}
        </div>
    ` : '';

    msgRow.innerHTML = `
        <div style="max-width: 85%; background: ${bubbleBg}; color: ${bubbleColor}; border: ${bubbleBorder}; border-radius: ${isMe ? '14px 14px 2px 14px' : '14px 14px 14px 2px'}; padding: 8px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.06);">
            ${senderHeader}
            ${contentHtml}
            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 4px; margin-top: 4px; font-size: 10.5px; opacity: 0.8;">
                <span>${formatTime(msg.created_at)}</span>
                ${isMe ? `<span class="msg-tick" data-msg-id="${msg.id}">${tickHtml(msg.status || statusFromReceipts(msg.id))}</span>` : ''}
            </div>
        </div>
    `;

    scrollEl.appendChild(msgRow);
}

// 9. Send a message
async function sendMessage() {
    const textarea = document.getElementById('chat-input-textarea');
    const text = textarea.value.trim();

    if (!text && !pendingUpload) {
        return;
    }

    if (!currentConvId) {
        return;
    }

    let payload = {
        action: 'send_message',
        conversation_id: currentConvId,
        msg_type: 'text',
        message: text
    };

    if (pendingUpload) {
        payload.msg_type = pendingUpload.msg_type;
        payload.attachment_url = pendingUpload.attachment_url;
        payload.file_name = pendingUpload.file_name;
        payload.file_size = pendingUpload.file_size;
        payload.mime_type = pendingUpload.mime_type;
        cancelUploadPreview();
    }

    textarea.value = '';
    textarea.style.height = 'auto';

    // Send over the socket when the WebSocket is open, otherwise fall back to the REST API
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(payload));
    } else {
        try {
            await fetch('/chat/api/messages', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            loadMessages(currentConvId);
        } catch (e) {
            alert('Mesaj gönderilemedi: ' + e.message);
        }
    }
}

// 10. File selection & upload
async function handleFileSelected(event, type) {
    const file = event.target.files[0];
    if (!file) return;

    // Size check (max 25MB)
    if (file.size > 25 * 1024 * 1024) {
        alert('Dosya boyutu çok büyük (Maksimum 25 MB).');
        event.target.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('file', file);

    const previewBar = document.getElementById('chat-upload-preview-bar');
    const filenameEl = document.getElementById('chat-upload-filename');
    const filesizeEl = document.getElementById('chat-upload-filesize');

    previewBar.style.display = 'flex';
    filenameEl.textContent = 'Yükleniyor: ' + file.name + '...';
    filesizeEl.textContent = '(' + formatFileSize(file.size) + ')';

    try {
        const res = await fetch('/chat/api/upload', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + window.CHAT_TOKEN },
            body: formData
        });
        const json = await res.json();
        if (json.success) {
            pendingUpload = json;
            filenameEl.textContent = file.name;
        } else {
            alert('Yükleme hatası: ' + (json.error || 'Bilinmeyen hata'));
            cancelUploadPreview();
        }
    } catch (e) {
        alert('Dosya yüklenirken hata oluştu: ' + e.message);
        cancelUploadPreview();
    } finally {
        event.target.value = '';
    }
}

function cancelUploadPreview() {
    pendingUpload = null;
    document.getElementById('chat-upload-preview-bar').style.display = 'none';
}

// 11. Typing event
function handleInputTyping() {
    if (!currentConvId || !ws || ws.readyState !== WebSocket.OPEN) return;

    if (!isTypingSent) {
        isTypingSent = true;
        ws.send(JSON.stringify({
            action: 'typing',
            conversation_id: currentConvId,
            is_typing: true
        }));
    }

    clearTimeout(typingTimeout);
    typingTimeout = setTimeout(() => {
        isTypingSent = false;
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
                action: 'typing',
                conversation_id: currentConvId,
                is_typing: false
            }));
        }
    }, 2000);
}

function showTypingIndicator(name, isTyping) {
    const el = document.getElementById('chat-typing-indicator');
    const textEl = document.getElementById('chat-typing-text');
    if (isTyping) {
        textEl.textContent = `${name} yazıyor...`;
        el.style.display = 'block';
    } else {
        el.style.display = 'none';
    }
}

// 12. Okundu Bildirimi
function markCurrentConversationRead(lastMsgId) {
    if (!currentConvId) return;
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({
            action: 'mark_read',
            conversation_id: currentConvId,
            last_message_id: lastMsgId || 0
        }));
    }
}

// Receipts of the open conversation (server "receipts" event), used for own
// messages appended after the event arrived.
window.currentReceipts = { read: 0, delivered: 0 };

function statusFromReceipts(id) {
    if (currentReceipts.read && id <= currentReceipts.read) return 'read';
    if (currentReceipts.delivered && id <= currentReceipts.delivered) return 'delivered';
    return 'sent';
}

function tickHtml(status) {
    if (status === 'read') return '<i class="fas fa-check-double u-fs-10" style="color: #7dd3fc;" title="Okundu"></i>';
    if (status === 'delivered') return '<i class="fas fa-check-double u-fs-10" title="İletildi"></i>';
    return '<i class="fas fa-check u-fs-10" title="Gönderildi"></i>';
}

function applyReceipts(readUpto, deliveredUpto) {
    currentReceipts = { read: readUpto || 0, delivered: deliveredUpto || 0 };
    document.querySelectorAll('#chat-messages-scroll .msg-tick').forEach(function (el) {
        el.innerHTML = tickHtml(statusFromReceipts(parseInt(el.dataset.msgId, 10)));
    });
}

function markUiMessagesAsRead(lastMsgId) {
    // Superseded by the "receipts" event, which also covers delivery and groups.
}

// 13. Presence and status update
window.chatLastSeen = window.chatLastSeen || {};

function lastSeenText(extension) {
    const iso = chatLastSeen[extension];
    if (!iso) return 'Çevrimdışı';
    const d = new Date(iso);
    const sameDay = d.toDateString() === new Date().toDateString();
    const hm = d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
    return 'Son görülme ' + (sameDay ? hm : d.toLocaleDateString('tr-TR') + ' ' + hm);
}

function updateUserPresence(extension, isOnline) {
    // Update the dot in the directory
    const dot = document.getElementById(`contact-dot-${extension}`);
    if (dot) {
        dot.style.background = isOnline ? '#10b981' : '#9ca3af';
    }
    // If it is the conversation open right now
    if (currentTargetExt === extension) {
        document.getElementById('active-target-status-dot').style.background = isOnline ? '#10b981' : '#9ca3af';
        document.getElementById('active-target-status-text').textContent = isOnline ? 'Çevrimiçi' : lastSeenText(extension);
        document.getElementById('active-target-status-text').style.color = isOnline ? '#10b981' : 'var(--text-muted)';
    }
}

// 14. Call the extension directly (PBX integration)
function callTargetExtension() {
    if (!currentTargetExt) return;
    // The portal's softphone (header_phone.js). It used to look for
    // dialNumber()/makeCall(), which did not exist, and opened a tel: link
    // in the browser when they were not found.
    if (typeof headerPhoneMakeCall === 'function') {
        const input = document.getElementById('header-quick-dial-input');
        if (input) input.value = currentTargetExt;
        headerPhoneMakeCall();
    } else {
        window.location.href = 'tel:' + currentTargetExt;
    }
}

// 15. Lightbox image viewer
function sanitizeAttachmentUrl(url) {
    if (!url || typeof url !== 'string') return '';
    const clean = url.trim();
    if (clean.startsWith('/chat/media/') || clean.startsWith('/media/')) {
        return clean;
    }
    return '';
}

function openSafeLightbox(el) {
    const url = el.getAttribute('data-url');
    if (url) {
        openLightbox(url);
    }
}

function openLightbox(url) {
    const safeUrl = sanitizeAttachmentUrl(url);
    if (!safeUrl) return;

    const modal = document.getElementById('chat-lightbox-modal');
    const img = document.getElementById('chat-lightbox-img');
    const downloadLink = document.getElementById('chat-lightbox-download');

    img.src = safeUrl;
    downloadLink.href = safeUrl;
    modal.style.display = 'flex';
}

function closeLightbox() {
    document.getElementById('chat-lightbox-modal').style.display = 'none';
    document.getElementById('chat-lightbox-img').src = '';
}

// 16. Web Audio notification sound (native ring without a file)
function playNotificationSound() {
    try {
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);

        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
        osc.frequency.setValueAtTime(880, audioCtx.currentTime + 0.08); // A5

        gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.3);

        osc.start(audioCtx.currentTime);
        osc.stop(audioCtx.currentTime + 0.3);
    } catch (e) {}
}

// 17. Helper functions
function handleInputKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function scrollToBottom() {
    const el = document.getElementById('chat-messages-scroll');
    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

function filterChatList(query) {
    const q = query.toLowerCase().trim();
    if (currentTab === 'convs') {
        const items = document.querySelectorAll('#chat-convs-list > div');
        items.forEach(el => {
            const text = el.textContent.toLowerCase();
            el.style.display = text.includes(q) ? 'flex' : 'none';
        });
    } else {
        const items = document.querySelectorAll('#chat-contacts-list > div');
        items.forEach(el => {
            const text = el.textContent.toLowerCase();
            el.style.display = text.includes(q) ? 'flex' : 'none';
        });
    }
}

function formatTime(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr.replace(/-/g, '/'));
        const now = new Date();
        const isToday = d.toDateString() === now.toDateString();
        const h = String(d.getHours()).padStart(2, '0');
        const m = String(d.getMinutes()).padStart(2, '0');
        if (isToday) return `${h}:${m}`;
        const day = String(d.getDate()).padStart(2, '0');
        const mon = String(d.getMonth() + 1).padStart(2, '0');
        return `${day}.${mon} ${h}:${m}`;
    } catch (e) {
        return dateStr;
    }
}

function formatFileSize(bytes) {
    if (!bytes || bytes <= 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 18. Group management and helper functions
function getSenderColor(ext) {
    if (!ext) return '#3b82f6';
    const colors = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#4f46e5', '#d97706'];
    let hash = 0;
    for (let i = 0; i < ext.length; i++) {
        hash = ext.charCodeAt(i) + ((hash << 5) - hash);
    }
    return colors[Math.abs(hash) % colors.length];
}

function closeActiveConversation() {
    currentConvId = null;
    currentConv = null;
    currentTargetExt = null;
    document.getElementById('chat-active-pane').style.display = 'none';
    document.getElementById('chat-empty-state').style.display = 'flex';
    closeGroupInfoModal();
}

function handleHeaderClick() {
    if (currentConv && currentConv.type === 'group') {
        openGroupInfoModal();
    }
}

function openNewGroupModal() {
    document.getElementById('new-group-title').value = '';
    document.getElementById('new-group-desc').value = '';
    document.getElementById('new-group-search-contacts').value = '';
    newGroupAvatarUrl = '';
    document.getElementById('new-group-avatar-preview').innerHTML = '<i class="fas fa-camera"></i>';
    document.getElementById('new-group-selected-count').textContent = '0';

    const listEl = document.getElementById('new-group-contacts-list');
    listEl.innerHTML = '';

    if (contacts.length === 0) {
        listEl.innerHTML = '<div style="padding: 12px; text-align: center; color: var(--text-muted); font-size: 12px;">Rehberde kullanıcı bulunamadı.</div>';
    } else {
        contacts.forEach(u => {
            const row = document.createElement('label');
            row.style.cssText = 'display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 6px; cursor: pointer; margin: 0; user-select: none;';
            row.onmouseenter = () => row.style.background = 'var(--bg-main)';
            row.onmouseleave = () => row.style.background = 'transparent';
            row.innerHTML = `
                <input type="checkbox" value="${escapeHtml(u.extension)}" class="new-group-contact-cb" onchange="updateNewGroupSelectedCount()" style="cursor: pointer;">
                <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;">
                    ${escapeHtml((u.full_name || u.extension).charAt(0).toUpperCase())}
                </div>
                <div style="flex: 1; min-width: 0; font-size: 13px; color: var(--text-main); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                    ${escapeHtml(u.full_name)} <span class="u-muted u-fs-11">(#${escapeHtml(u.extension)})</span>
                </div>
            `;
            listEl.appendChild(row);
        });
    }

    document.getElementById('chat-new-group-modal').style.display = 'flex';
    document.getElementById('new-group-title').focus();
}

function closeNewGroupModal() {
    document.getElementById('chat-new-group-modal').style.display = 'none';
}

function filterNewGroupContacts(query) {
    const q = query.toLowerCase().trim();
    const rows = document.querySelectorAll('#new-group-contacts-list > label');
    rows.forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? 'flex' : 'none';
    });
}

function updateNewGroupSelectedCount() {
    const checked = document.querySelectorAll('.new-group-contact-cb:checked');
    document.getElementById('new-group-selected-count').textContent = checked.length;
}

async function handleNewGroupAvatarSelect(event) {
    const file = event.target.files[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('file', file);
    try {
        const res = await fetch('/chat/api/upload?type=avatar', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + window.CHAT_TOKEN },
            body: formData
        });
        const json = await res.json();
        if (json.success && json.attachment_url) {
            newGroupAvatarUrl = json.attachment_url;
            const safe = sanitizeAttachmentUrl(json.attachment_url);
            document.getElementById('new-group-avatar-preview').innerHTML = `<img src="${escapeHtml(safe)}" style="width: 100%; height: 100%; object-fit: cover;">`;
        } else {
            alert('Grup resmi yüklenemedi: ' + (json.error || 'Hata'));
        }
    } catch (e) {
        alert('Resim yükleme hatası: ' + e.message);
    } finally {
        event.target.value = '';
    }
}

async function submitCreateGroup() {
    const title = document.getElementById('new-group-title').value.trim();
    const desc = document.getElementById('new-group-desc').value.trim();
    if (!title) {
        alert('Lütfen bir grup adı girin.');
        document.getElementById('new-group-title').focus();
        return;
    }

    const checkedBoxes = document.querySelectorAll('.new-group-contact-cb:checked');
    const members = [];
    checkedBoxes.forEach(cb => members.push(cb.value));

    const submitBtn = document.getElementById('new-group-submit-btn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Oluşturuluyor...';

    try {
        const res = await fetch('/chat/api/conversations/group', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                title: title,
                description: desc,
                avatar_url: newGroupAvatarUrl,
                members: members
            })
        });
        const json = await res.json();
        if (json.success && json.conversation) {
            closeNewGroupModal();
            switchChatTab('convs');
            openConversation(json.conversation);
            loadConversations();
        } else {
            alert('Grup oluşturulamadı: ' + (json.error || 'Hata'));
        }
    } catch (e) {
        alert('İstek hatası: ' + e.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Grubu Oluştur';
    }
}

async function fetchGroupDetails(convId) {
    try {
        const res = await fetch(`/chat/api/conversations/group?conversation_id=${convId}`, {
            headers: { 'Authorization': 'Bearer ' + window.CHAT_TOKEN }
        });
        const json = await res.json();
        if (json.success && json.conversation) {
            if (currentConv && currentConv.id === convId) {
                currentConv = Object.assign(currentConv, json.conversation);
                const count = currentConv.member_count || (currentConv.participants ? currentConv.participants.length : 0);
                document.getElementById('active-target-ext').textContent = `${count} üye`;
            }
            return json.conversation;
        }
    } catch(e) {}
    return null;
}

async function openGroupInfoModal() {
    if (!currentConv || currentConv.type !== 'group') return;
    const modal = document.getElementById('chat-group-info-modal');
    modal.style.display = 'flex';

    // Fetch the details and render
    const conv = await fetchGroupDetails(currentConv.id) || currentConv;
    renderGroupInfo(conv);
}

function closeGroupInfoModal() {
    document.getElementById('chat-group-info-modal').style.display = 'none';
}

function renderGroupInfo(conv) {
    const avatarBox = document.getElementById('group-info-avatar-box');
    if (conv.avatar_url) {
        avatarBox.style.background = 'transparent';
        avatarBox.innerHTML = `<img src="${escapeHtml(sanitizeAttachmentUrl(conv.avatar_url))}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
    } else {
        avatarBox.style.background = 'linear-gradient(135deg, #6366f1, #8b5cf6)';
        avatarBox.innerHTML = '<i class="fas fa-users"></i>';
    }

    document.getElementById('group-info-title').textContent = conv.title || 'Grup';
    document.getElementById('group-info-desc').textContent = conv.description || 'Açıklama belirtilmemiş.';
    document.getElementById('group-info-meta').textContent = `Oluşturan: #${conv.created_by || ''} • ${conv.created_at ? formatTime(conv.created_at) : ''}`;

    const isAdmin = conv.my_role === 'admin';
    document.getElementById('group-info-edit-btn').style.display = isAdmin ? 'inline-block' : 'none';
    document.getElementById('group-info-add-member-btn').style.display = isAdmin ? 'inline-block' : 'none';
    document.getElementById('group-info-delete-btn').style.display = isAdmin ? 'inline-block' : 'none';

    const participants = conv.participants || [];
    document.getElementById('group-info-members-count').textContent = participants.length;

    const listEl = document.getElementById('group-info-members-list');
    listEl.innerHTML = '';

    participants.forEach(p => {
        const row = document.createElement('div');
        row.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 6px 8px; border-radius: 6px; background: var(--bg-card);';
        row.onmouseenter = () => row.style.background = 'var(--bg-main)';
        row.onmouseleave = () => row.style.background = 'var(--bg-card)';

        const isMe = p.extension === window.MY_EXT;
        const onlineColor = p.is_online ? '#10b981' : '#9ca3af';
        const roleBadge = p.role === 'admin' 
            ? '<span class="badge" style="background: rgba(99, 102, 241, 0.15); color: #6366f1; font-size: 10.5px; padding: 2px 6px; border-radius: 6px;">Yönetici</span>'
            : '';

        let actionsHtml = '';
        if (isAdmin && !isMe) {
            const toggleRoleAction = p.role === 'admin'
                ? `<button class="btn btn-sm btn-outline-secondary" style="padding: 1px 6px; font-size: 11px;" onclick="updateMemberRole('${escapeHtml(p.extension)}', '${escapeHtml(p.full_name)}', 'member')" title="Yöneticiliği Kaldır"><i class="fas fa-user-minus"></i></button>`
                : `<button class="btn btn-sm btn-outline-primary" style="padding: 1px 6px; font-size: 11px;" onclick="updateMemberRole('${escapeHtml(p.extension)}', '${escapeHtml(p.full_name)}', 'admin')" title="Yönetici Yap"><i class="fas fa-user-shield"></i></button>`;

            actionsHtml = `
                <div style="display: flex; align-items: center; gap: 4px;">
                    ${toggleRoleAction}
                    <button class="btn btn-sm btn-outline-danger" style="padding: 1px 6px; font-size: 11px;" onclick="removeMemberFromGroup('${escapeHtml(p.extension)}', '${escapeHtml(p.full_name)}')" title="Gruptan Çıkar">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        }

        row.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                <div style="position: relative; width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0;">
                    ${escapeHtml((p.full_name || p.extension).charAt(0).toUpperCase())}
                    <span style="position: absolute; bottom: 0; right: 0; width: 8px; height: 8px; border-radius: 50%; background: ${onlineColor}; border: 1.5px solid var(--bg-card);"></span>
                </div>
                <div style="min-width: 0;">
                    <div style="font-size: 13px; font-weight: 600; color: var(--text-main); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                        ${escapeHtml(p.full_name)} ${isMe ? '<span class="u-muted u-fs-11">(Siz)</span>' : ''}
                    </div>
                    <div class="u-muted u-fs-11">
                        #${escapeHtml(p.extension)}
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                ${roleBadge}
                ${actionsHtml}
            </div>
        `;
        listEl.appendChild(row);
    });
}

async function promptEditGroupInfo() {
    if (!currentConv) return;
    const newTitle = prompt('Grup Adı:', currentConv.title || '');
    if (newTitle === null) return;
    const trimmedTitle = newTitle.trim();
    if (!trimmedTitle) {
        alert('Grup adı boş olamaz.');
        return;
    }
    const newDesc = prompt('Grup Açıklaması:', currentConv.description || '') || '';

    try {
        const res = await fetch('/chat/api/conversations/group/update', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                conversation_id: currentConv.id,
                title: trimmedTitle,
                description: newDesc.trim(),
                avatar_url: currentConv.avatar_url || ''
            })
        });
        const json = await res.json();
        if (json.success) {
            currentConv.title = trimmedTitle;
            currentConv.description = newDesc.trim();
            document.getElementById('active-target-name').textContent = trimmedTitle;
            renderGroupInfo(currentConv);
            loadConversations();
        } else {
            alert('Güncelleme hatası: ' + (json.error || 'Hata'));
        }
    } catch(e) {
        alert('İstek hatası: ' + e.message);
    }
}

function openAddMembersModal() {
    if (!currentConv) return;
    const modal = document.getElementById('chat-add-members-modal');
    document.getElementById('add-members-search').value = '';
    const listEl = document.getElementById('add-members-contacts-list');
    listEl.innerHTML = '';

    const currentMemberExts = new Set((currentConv.participants || []).map(p => p.extension));
    const availableContacts = contacts.filter(u => !currentMemberExts.has(u.extension));

    if (availableContacts.length === 0) {
        listEl.innerHTML = '<div style="padding: 14px; text-align: center; color: var(--text-muted); font-size: 12px;">Eklenebilecek başka kullanıcı bulunmuyor.</div>';
    } else {
        availableContacts.forEach(u => {
            const row = document.createElement('label');
            row.style.cssText = 'display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 6px; cursor: pointer; margin: 0; user-select: none;';
            row.onmouseenter = () => row.style.background = 'var(--bg-main)';
            row.onmouseleave = () => row.style.background = 'transparent';
            row.innerHTML = `
                <input type="checkbox" value="${escapeHtml(u.extension)}" class="add-members-cb" style="cursor: pointer;">
                <div style="width: 24px; height: 24px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;">
                    ${escapeHtml((u.full_name || u.extension).charAt(0).toUpperCase())}
                </div>
                <div style="flex: 1; min-width: 0; font-size: 13px; color: var(--text-main); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                    ${escapeHtml(u.full_name)} <span class="u-muted u-fs-11">(#${escapeHtml(u.extension)})</span>
                </div>
            `;
            listEl.appendChild(row);
        });
    }

    modal.style.display = 'flex';
}

function closeAddMembersModal() {
    document.getElementById('chat-add-members-modal').style.display = 'none';
}

function filterAddMembersContacts(query) {
    const q = query.toLowerCase().trim();
    const rows = document.querySelectorAll('#add-members-contacts-list > label');
    rows.forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? 'flex' : 'none';
    });
}

async function submitAddMembers() {
    if (!currentConv) return;
    const checked = document.querySelectorAll('.add-members-cb:checked');
    const exts = [];
    checked.forEach(cb => exts.push(cb.value));

    if (exts.length === 0) {
        alert('Lütfen en az bir kişi seçin.');
        return;
    }

    const btn = document.getElementById('add-members-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ekleniyor...';

    try {
        const res = await fetch('/chat/api/conversations/group/members/add', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                conversation_id: currentConv.id,
                extensions: exts
            })
        });
        const json = await res.json();
        if (json.success) {
            closeAddMembersModal();
            const updated = await fetchGroupDetails(currentConv.id);
            if (updated) renderGroupInfo(updated);
            loadConversations();
        } else {
            alert('Üye ekleme hatası: ' + (json.error || 'Hata'));
        }
    } catch(e) {
        alert('İstek hatası: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-user-plus"></i> Ekle';
    }
}

async function removeMemberFromGroup(targetExt, targetName) {
    if (!currentConv) return;
    if (!confirm(`${targetName || ('#' + targetExt)} kullanıcısını gruptan çıkarmak istediğinize emin misiniz?`)) {
        return;
    }

    try {
        const res = await fetch('/chat/api/conversations/group/members/remove', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                conversation_id: currentConv.id,
                extension: targetExt
            })
        });
        const json = await res.json();
        if (json.success) {
            const updated = await fetchGroupDetails(currentConv.id);
            if (updated) renderGroupInfo(updated);
            loadConversations();
        } else {
            alert('Çıkarma hatası: ' + (json.error || 'Hata'));
        }
    } catch(e) {
        alert('İstek hatası: ' + e.message);
    }
}

async function updateMemberRole(targetExt, targetName, newRole) {
    if (!currentConv) return;
    const roleText = newRole === 'admin' ? 'yönetici' : 'üye';
    if (!confirm(`${targetName || ('#' + targetExt)} kullanıcısını ${roleText} yapmak istediğinize emin misiniz?`)) {
        return;
    }

    try {
        const res = await fetch('/chat/api/conversations/group/members/role', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                conversation_id: currentConv.id,
                extension: targetExt,
                role: newRole
            })
        });
        const json = await res.json();
        if (json.success) {
            const updated = await fetchGroupDetails(currentConv.id);
            if (updated) renderGroupInfo(updated);
        } else {
            alert('Yetki değiştirme hatası: ' + (json.error || 'Hata'));
        }
    } catch(e) {
        alert('İstek hatası: ' + e.message);
    }
}

async function confirmLeaveGroup() {
    if (!currentConv) return;
    if (!confirm(`"${currentConv.title}" grubundan ayrılmak istediğinize emin misiniz?`)) {
        return;
    }

    try {
        const res = await fetch('/chat/api/conversations/group/leave', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ conversation_id: currentConv.id })
        });
        const json = await res.json();
        if (json.success) {
            closeActiveConversation();
            loadConversations();
        } else {
            alert('Gruptan ayrılma hatası: ' + (json.error || 'Hata'));
        }
    } catch(e) {
        alert('İstek hatası: ' + e.message);
    }
}

async function confirmDeleteGroup() {
    if (!currentConv) return;
    if (!confirm(`"${currentConv.title}" grubunu silmek istediğinize emin misiniz? Bu işlem geri alınamaz.`)) {
        return;
    }

    try {
        const res = await fetch('/chat/api/conversations/group/delete', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + window.CHAT_TOKEN,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ conversation_id: currentConv.id })
        });
        const json = await res.json();
        if (json.success) {
            closeActiveConversation();
            loadConversations();
        } else {
            alert('Grup silme hatası: ' + (json.error || 'Hata'));
        }
    } catch(e) {
        alert('İstek hatası: ' + e.message);
    }
}
