<?php
/**
 * AI-PBX Chat & Media Sharing View
 */
$ext = $ext ?? '';
$user = $user ?? [];
$token = $token ?? '';
?>

<div style="padding: 15px; height: calc(100vh - 120px); min-height: 580px; display: flex; flex-direction: column;">
    <!-- Chat Card Container -->
    <div class="card" style="flex: 1; display: flex; flex-direction: row; overflow: hidden; padding: 0; border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
        
        <!-- SOL PANEL: SOHBETLER VE REHBER -->
        <div id="chat-sidebar" style="width: 340px; min-width: 280px; border-right: 1px solid var(--border-color); display: flex; flex-direction: column; background: var(--bg-card);">
            
            <!-- Sidebar Header & Arama -->
            <div style="padding: 14px 16px; border-bottom: 1px solid var(--border-color);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-comments" style="color: var(--primary);"></i> Mesajlar
                    </h3>
                    <span id="chat-ws-status-badge" class="badge" style="font-size: 11px; padding: 3px 8px; background: rgba(0,0,0,0.05); color: var(--text-muted); border-radius: 10px;">
                        <i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: var(--warning);"></i> Bağlanıyor...
                    </span>
                </div>
                
                <!-- Sekmeler: Sohbetler / Kişiler -->
                <div style="display: flex; gap: 6px; margin-bottom: 10px; background: var(--bg-main); padding: 3px; border-radius: 8px;">
                    <button id="tab-btn-convs" class="btn btn-sm" style="flex: 1; border: none; background: var(--bg-card); color: var(--text-main); font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 6px; padding: 6px 10px;" onclick="switchChatTab('convs')">
                        <i class="fas fa-comment-dots"></i> Sohbetler
                    </button>
                    <button id="tab-btn-contacts" class="btn btn-sm" style="flex: 1; border: none; background: transparent; color: var(--text-muted); font-weight: 600; border-radius: 6px; padding: 6px 10px;" onclick="switchChatTab('contacts')">
                        <i class="fas fa-address-book"></i> Dahili Rehber
                    </button>
                </div>

                <!-- Arama Kutusu -->
                <div style="position: relative;">
                    <i class="fas fa-search" style="position: absolute; left: 12px; top: 10px; color: var(--text-muted); font-size: 13px;"></i>
                    <input type="text" id="chat-search-input" placeholder="İsim veya dahili ara..." style="width: 100%; padding: 8px 12px 8px 34px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 13px; outline: none;" oninput="filterChatList(this.value)">
                </div>
            </div>

            <!-- Liste: Konuşmalar / Rehber -->
            <div id="chat-list-container" style="flex: 1; overflow-y: auto; padding: 6px;">
                <div id="chat-list-loading" style="text-align: center; padding: 30px; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 20px;"></i>
                    <div style="margin-top: 8px; font-size: 12px;">Yükleniyor...</div>
                </div>
                <div id="chat-convs-list" style="display: flex; flex-direction: column; gap: 2px;"></div>
                <div id="chat-contacts-list" style="display: none; flex-direction: column; gap: 2px;"></div>
            </div>

            <!-- Kullanıcı Kendi Bilgisi Alt Barı -->
            <div style="padding: 10px 14px; border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: var(--bg-main); font-size: 12px;">
                <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0;">
                        <?php echo strtoupper(mb_substr($user['full_name'] ?? $user['username'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <span style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></span>
                        <span style="color: var(--text-muted); font-size: 11px;">(#<?php echo htmlspecialchars($ext); ?>)</span>
                    </div>
                </div>
                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; font-size: 11px; padding: 3px 8px; border-radius: 8px;">
                    Aktif
                </span>
            </div>
        </div>

        <!-- SAĞ PANEL: AKTİF SOHBET PENCERESİ -->
        <div id="chat-main" style="flex: 1; display: flex; flex-direction: column; background: var(--bg-main); position: relative;">
            
            <!-- Boş Durum (Sohbet Seçilmediğinde) -->
            <div id="chat-empty-state" style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-muted); padding: 30px; text-align: center;">
                <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(0, 242, 254, 0.08); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 34px; margin-bottom: 16px;">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <h4 style="margin: 0 0 8px 0; color: var(--text-main); font-size: 18px; font-weight: 700;">AI-PBX Anlık Mesajlaşma</h4>
                <p style="margin: 0; max-width: 360px; font-size: 13.5px; line-height: 1.5;">
                    Soldaki listeden bir konuşma seçin veya Dahili Rehber sekmesinden çalışma arkadaşınızla sohbet başlatın.
                </p>
                <button class="btn btn-primary btn-sm" style="margin-top: 20px; border-radius: 20px; padding: 8px 20px;" onclick="switchChatTab('contacts')">
                    <i class="fas fa-user-plus"></i> Rehbere Göz At
                </button>
            </div>

            <!-- Aktif Konuşma Arayüzü -->
            <div id="chat-active-pane" style="display: none; flex: 1; flex-direction: column; height: 100%; overflow: hidden;">
                
                <!-- Aktif Sohbet Başlığı -->
                <div id="chat-header" style="padding: 12px 18px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div id="active-target-avatar" style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; position: relative;">
                            <span id="active-target-initial">U</span>
                            <span id="active-target-status-dot" style="position: absolute; bottom: 0; right: 0; width: 11px; height: 11px; border-radius: 50%; background: #9ca3af; border: 2px solid var(--bg-card);"></span>
                        </div>
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <h4 id="active-target-name" style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main);">Kullanıcı</h4>
                                <span id="active-target-ext" class="badge" style="background: rgba(0,0,0,0.06); color: var(--text-muted); font-size: 11px; border-radius: 6px; padding: 2px 6px;">#0000</span>
                            </div>
                            <div id="active-target-status-text" style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                Çevrimdışı
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hızlı İşlemler: Ara / Kapat -->
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button id="active-target-call-btn" class="btn btn-sm btn-outline-primary" title="Dahiliyi Ara" style="border-radius: 8px; padding: 6px 12px;" onclick="callTargetExtension()">
                            <i class="fas fa-phone-alt"></i> <span class="d-none d-md-inline" style="margin-left: 4px;">Ara</span>
                        </button>
                    </div>
                </div>

                <!-- Mesaj Akışı -->
                <div id="chat-messages-scroll" style="flex: 1; overflow-y: auto; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px;">
                    <!-- Mesajlar dinamik eklenecek -->
                </div>

                <!-- Yazıyor Bildirimi -->
                <div id="chat-typing-indicator" style="display: none; padding: 4px 20px; font-size: 12px; color: var(--text-muted); font-style: italic;">
                    <i class="fas fa-ellipsis-h fa-bounce" style="margin-right: 4px;"></i> <span id="chat-typing-text">yazıyor...</span>
                </div>

                <!-- Dosya / Resim Yükleme Önizleme Barı -->
                <div id="chat-upload-preview-bar" style="display: none; padding: 8px 16px; background: rgba(0, 242, 254, 0.08); border-top: 1px solid var(--border-color); align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--text-main);">
                        <i class="fas fa-cloud-upload-alt" style="color: var(--primary);"></i>
                        <span id="chat-upload-filename" style="font-weight: 600;">dosya.png</span>
                        <span id="chat-upload-filesize" style="color: var(--text-muted);">(0 KB)</span>
                    </div>
                    <button class="btn btn-sm" style="border: none; background: transparent; color: var(--danger);" onclick="cancelUploadPreview()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Mesaj Gönderme Kutusu -->
                <div style="padding: 12px 18px; background: var(--bg-card); border-top: 1px solid var(--border-color); display: flex; align-items: center; gap: 8px;">
                    <!-- Gizli Dosya Seçiciler -->
                    <input type="file" id="chat-file-input" style="display: none;" onchange="handleFileSelected(event, 'file')" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    <input type="file" id="chat-photo-input" style="display: none;" onchange="handleFileSelected(event, 'image')" accept="image/*">

                    <!-- Ek Butonları -->
                    <button type="button" class="btn btn-sm" title="Dosya / Belge Ekle" style="border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-muted); border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;" onclick="document.getElementById('chat-file-input').click()">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <button type="button" class="btn btn-sm" title="Fotoğraf Gönder" style="border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-muted); border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;" onclick="document.getElementById('chat-photo-input').click()">
                        <i class="fas fa-camera"></i>
                    </button>

                    <!-- Metin Girdisi -->
                    <div style="flex: 1; position: relative;">
                        <textarea id="chat-input-textarea" rows="1" placeholder="Bir mesaj yazın... (Göndermek için Enter, yeni satır için Shift+Enter)" style="width: 100%; resize: none; max-height: 120px; padding: 9px 14px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 13.5px; outline: none; line-height: 1.4;" onkeydown="handleInputKeydown(event)" oninput="handleInputTyping()"></textarea>
                    </div>

                    <!-- Gönder Butonu -->
                    <button id="chat-send-btn" type="button" class="btn btn-primary" title="Gönder" style="border-radius: 10px; width: 42px; height: 38px; display: flex; align-items: center; justify-content: center;" onclick="sendMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Resim Tam Ekran Lightbox Modal -->
<div id="chat-lightbox-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 99999; align-items: center; justify-content: center; padding: 20px;" onclick="closeLightbox()">
    <div style="position: relative; max-width: 90vw; max-height: 90vh;">
        <img id="chat-lightbox-img" src="" style="max-width: 100%; max-height: 85vh; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);" onclick="event.stopPropagation()">
        <a id="chat-lightbox-download" href="" target="_blank" download style="position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,0.6); color: #fff; padding: 8px 14px; border-radius: 8px; font-size: 13px; text-decoration: none;" onclick="event.stopPropagation()">
            <i class="fas fa-download"></i> İndir
        </a>
    </div>
</div>

<script>
// Global Chat Konfigürasyonu
const CHAT_TOKEN = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const MY_EXT = <?= json_encode($ext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const MY_NAME = <?= json_encode($user['full_name'] ?? $user['username'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Chat WebSocket & Sayfa Durum Yöneticisi (Singleton)
window._chatWsState = window._chatWsState || {
    ws: null,
    reconnectTimer: null,
    intentionalClose: false
};

let currentConvId = null;
let currentTargetExt = null;
let conversations = [];
let contacts = [];
let currentTab = 'convs';
let pendingUpload = null;
let typingTimeout = null;
let isTypingSent = false;
let audioCtx = null;

// Geriye dönük uyumluluk için ws getter/setter proxy
let ws = null;

function getChatWs() {
    return window._chatWsState ? window._chatWsState.ws : null;
}

function initChatPage() {
    if (!document.getElementById('chat-ws-status-badge')) {
        return; // Chat sayfasında değilsek çalışma
    }
    initChatWebSocket();
    loadConversations();
    loadContacts();
}

// SPA Sayfa Geçiş Dinleyicisi (Tekil kayıt)
if (!window._chatSpaHookRegistered) {
    window._chatSpaHookRegistered = true;
    document.addEventListener('spa:pageLoaded', function(e) {
        if (window.location.pathname.startsWith('/chat')) {
            initChatPage();
        } else {
            // Chat sayfasından ayrılındıysa açık soketi ve zamanlayıcıları temizle
            cleanupChatWebSocket();
        }
    });
}

// İlk yükleme (Normal browser refresh)
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    initChatPage();
} else {
    document.addEventListener('DOMContentLoaded', initChatPage, { once: true });
}

// 1. WebSocket Bağlantısı ve Yaşam Döngüsü
function initChatWebSocket() {
    const badge = document.getElementById('chat-ws-status-badge');
    if (!badge) {
        return; // Sayfada badge yoksa çalışma
    }

    const state = window._chatWsState;

    // Eğer zaten bağlıysa veya bağlanma sürecindeyse mükerrer soket açma!
    if (state.ws && (state.ws.readyState === WebSocket.OPEN || state.ws.readyState === WebSocket.CONNECTING)) {
        ws = state.ws;
        if (state.ws.readyState === WebSocket.OPEN) {
            badge.innerHTML = '<i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: #10b981;"></i> Bağlandı';
            badge.style.color = '#10b981';
        }
        return;
    }

    // Bekleyen reconnect zamanlayıcısını temizle
    if (state.reconnectTimer) {
        clearTimeout(state.reconnectTimer);
        state.reconnectTimer = null;
    }

    state.intentionalClose = false;

    // Önceki soket kapanırken reconnect tetiklemesin diye dinleyicilerini sıfırla
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
    const wsUrl = `${wsProto}//${window.location.host}/chat/ws?token=${encodeURIComponent(CHAT_TOKEN)}`;

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
        // Eğer açık olan konuşmaya aitse ekrana bas
        if (currentConvId && msg.conversation_id === currentConvId) {
            appendMessageToUI(msg);
            scrollToBottom();
            // Okundu işaretle
            markCurrentConversationRead(msg.id);
        } else {
            // Başka bir konuşmaysa ses çal ve unread artır
            playNotificationSound();
        }
        // Konuşma listesini güncelle / yeniden çek
        loadConversations();

    } else if (evt.event === 'presence') {
        updateUserPresence(evt.extension, evt.is_online);

    } else if (evt.event === 'typing') {
        if (currentConvId && evt.conversation_id === currentConvId) {
            showTypingIndicator(evt.from_name, evt.is_typing);
        }

    } else if (evt.event === 'messages_read') {
        if (currentConvId && evt.conversation_id === currentConvId) {
            markUiMessagesAsRead(evt.last_message_id);
        }
    }
}

// 3. Konuşmaları ve Rehberi Çek
async function loadConversations() {
    try {
        const res = await fetch('/chat/api/conversations', {
            headers: { 'Authorization': 'Bearer ' + CHAT_TOKEN }
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
            headers: { 'Authorization': 'Bearer ' + CHAT_TOKEN }
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

// 4. Liste Render Fonksiyonları
function renderConversationsList() {
    const listEl = document.getElementById('chat-convs-list');
    listEl.innerHTML = '';

    if (conversations.length === 0) {
        listEl.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px;">Henüz bir sohbetiniz yok.<br>Rehberden bir dahili seçip mesajlaşabilirsiniz.</div>';
        return;
    }

    conversations.forEach(c => {
        const isSelected = currentConvId === c.id;
        const item = document.createElement('div');
        item.style.cssText = `
            display: flex; align-items: center; gap: 10px; padding: 10px 12px;
            border-radius: 10px; cursor: pointer; transition: background 0.15s;
            background: ${isSelected ? 'rgba(0, 242, 254, 0.12)' : 'transparent'};
        `;
        item.onmouseenter = () => { if (!isSelected) item.style.background = 'var(--bg-main)'; };
        item.onmouseleave = () => { if (!isSelected) item.style.background = 'transparent'; };
        item.onclick = () => openConversation(c);

        const initial = (c.target_name || c.target_ext || 'U').charAt(0).toUpperCase();
        const onlineColor = c.target_online ? '#10b981' : '#9ca3af';

        item.innerHTML = `
            <div style="position: relative; width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                ${initial}
                <span style="position: absolute; bottom: 0; right: 0; width: 10px; height: 10px; border-radius: 50%; background: ${onlineColor}; border: 2px solid var(--bg-card);"></span>
            </div>
            <div style="flex: 1; min-width: 0;">
                <div style="display: flex; justify-content: space-between; align-items: baseline;">
                    <span style="font-size: 13.5px; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${escapeHtml(c.target_name || c.target_ext)}
                    </span>
                    <span style="font-size: 11px; color: var(--text-muted); margin-left: 6px;">
                        ${c.last_message_at ? formatTime(c.last_message_at) : ''}
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2px;">
                    <span style="font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px;">
                        ${escapeHtml(c.last_message_text || 'Sohbet başlatıldı')}
                    </span>
                    ${c.unread_count > 0 ? `<span class="badge" style="background: var(--primary); color: #fff; font-size: 10.5px; padding: 2px 6px; border-radius: 10px; font-weight: 700;">${c.unread_count}</span>` : ''}
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
                ${initial}
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

// 5. Sekme Değiştirme (Sohbetler / Rehber)
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

// 6. Doğrudan Sohbet Başlat
async function startDirectChatWith(targetExt, targetName) {
    try {
        const res = await fetch('/chat/api/conversations/direct', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + CHAT_TOKEN,
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

// 7. Konuşmayı Aç
async function openConversation(conv) {
    currentConvId = conv.id;
    currentTargetExt = conv.target_ext;

    document.getElementById('chat-empty-state').style.display = 'none';
    document.getElementById('chat-active-pane').style.display = 'flex';

    // Header güncelle
    const initial = (conv.target_name || conv.target_ext || 'U').charAt(0).toUpperCase();
    document.getElementById('active-target-initial').textContent = initial;
    document.getElementById('active-target-name').textContent = conv.target_name || conv.target_ext;
    document.getElementById('active-target-ext').textContent = '#' + conv.target_ext;

    const isOnline = conv.target_online;
    document.getElementById('active-target-status-dot').style.background = isOnline ? '#10b981' : '#9ca3af';
    document.getElementById('active-target-status-text').textContent = isOnline ? 'Çevrimiçi' : 'Çevrimdışı';
    document.getElementById('active-target-status-text').style.color = isOnline ? '#10b981' : 'var(--text-muted)';

    // Mesajları çek
    await loadMessages(conv.id);

    // Listeyi yeniden render et (seçili arkaplanı güncellemek için)
    renderConversationsList();

    // Textarea'ya odaklan
    document.getElementById('chat-input-textarea').focus();
}

async function loadMessages(convId) {
    const scrollEl = document.getElementById('chat-messages-scroll');
    scrollEl.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Mesajlar yükleniyor...</div>';

    try {
        const res = await fetch(`/chat/api/messages?conversation_id=${convId}&limit=50`, {
            headers: { 'Authorization': 'Bearer ' + CHAT_TOKEN }
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

// 8. Mesajı Ekrana Ekle
function appendMessageToUI(msg) {
    const scrollEl = document.getElementById('chat-messages-scroll');
    const isMe = msg.is_me || msg.sender_ext === MY_EXT;

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
                <img src="${escapeHtml(safeUrl)}" alt="Fotoğraf" style="max-width: 260px; max-height: 260px; border-radius: 8px; object-fit: cover; display: block; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
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
                <div style="overflow: hidden; max-width: 180px;">
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

    msgRow.innerHTML = `
        <div style="max-width: 75%; background: ${bubbleBg}; color: ${bubbleColor}; border: ${bubbleBorder}; border-radius: ${isMe ? '14px 14px 2px 14px' : '14px 14px 14px 2px'}; padding: 8px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.06);">
            ${contentHtml}
            <div style="display: flex; align-items: center; justify-content: flex-end; gap: 4px; margin-top: 4px; font-size: 10.5px; opacity: 0.8;">
                <span>${formatTime(msg.created_at)}</span>
                ${isMe ? '<i class="fas fa-check-double" style="font-size: 10px;"></i>' : ''}
            </div>
        </div>
    `;

    scrollEl.appendChild(msgRow);
}

// 9. Mesaj Gönder
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

    // WebSocket açıksa soketten gönder, değilse REST API fallback
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(payload));
    } else {
        try {
            await fetch('/chat/api/messages', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + CHAT_TOKEN,
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

// 10. Dosya Seçimi & Yükleme
async function handleFileSelected(event, type) {
    const file = event.target.files[0];
    if (!file) return;

    // Boyut kontrolü (max 25MB)
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
            headers: { 'Authorization': 'Bearer ' + CHAT_TOKEN },
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

// 11. Yazıyor Olayı
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

function markUiMessagesAsRead(lastMsgId) {
    // Ekranda okunmamış tek tikleri çift tike çevir
}

// 13. Varlık ve Durum Güncellemesi
function updateUserPresence(extension, isOnline) {
    // Rehberdeki noktayı güncelle
    const dot = document.getElementById(`contact-dot-${extension}`);
    if (dot) {
        dot.style.background = isOnline ? '#10b981' : '#9ca3af';
    }
    // Eğer şu an açık olan konuşmaysa
    if (currentTargetExt === extension) {
        document.getElementById('active-target-status-dot').style.background = isOnline ? '#10b981' : '#9ca3af';
        document.getElementById('active-target-status-text').textContent = isOnline ? 'Çevrimiçi' : 'Çevrimdışı';
        document.getElementById('active-target-status-text').style.color = isOnline ? '#10b981' : 'var(--text-muted)';
    }
}

// 14. Dahiliyi Doğrudan Ara (Santral Entegrasyonu)
function callTargetExtension() {
    if (!currentTargetExt) return;
    if (window.parent && window.parent.dialNumber) {
        window.parent.dialNumber(currentTargetExt);
    } else if (typeof makeCall === 'function') {
        makeCall(currentTargetExt);
    } else {
        window.location.href = 'tel:' + currentTargetExt;
    }
}

// 15. Lightbox Görsel Görüntüleyici
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

// 16. Web Audio Bildirim Sesi (Dosyasız Native Zil)
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

// 17. Yardımcı Fonksiyonlar
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
</script>
