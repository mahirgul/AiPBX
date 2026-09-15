<?php
/**
 * AI-PBX Chat & Media Sharing View
 */
$ext = $ext ?? '';
$user = $user ?? [];
$token = $token ?? '';
?>

<style>
.chat-container {
    padding: 15px;
    height: calc(100vh - 120px);
    height: calc(100dvh - 120px);
    min-height: 580px;
    display: flex;
    flex-direction: column;
}
.chat-card-wrapper {
    flex: 1;
    display: flex;
    flex-direction: row;
    overflow: hidden;
    padding: 0 !important;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    position: relative;
    height: 100%;
}
.chat-sidebar {
    width: 340px;
    min-width: 280px;
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    background: var(--bg-card);
    height: 100%;
}
.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: var(--bg-main);
    position: relative;
    height: 100%;
    min-width: 0;
}
.chat-mobile-back-btn {
    display: none !important;
}

@media (max-width: 768px) {
    .chat-container {
        padding: 4px 6px !important;
        height: calc(100dvh - 110px) !important;
        min-height: 0 !important;
    }
    .chat-card-wrapper {
        border-radius: 8px !important;
        border: none !important;
        box-shadow: none !important;
    }
    .chat-sidebar {
        width: 100% !important;
        min-width: 0 !important;
        border-right: none !important;
        display: flex !important;
    }
    .chat-main {
        display: none !important;
        width: 100% !important;
    }
    .chat-card-wrapper.is-chat-open .chat-sidebar {
        display: none !important;
    }
    .chat-card-wrapper.is-chat-open .chat-main {
        display: flex !important;
        width: 100% !important;
    }
    .chat-mobile-back-btn {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: var(--bg-main);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        cursor: pointer;
        padding: 0;
        margin-right: 8px;
        flex-shrink: 0;
    }
    #chat-header {
        padding: 8px 12px !important;
    }
    #chat-messages-scroll {
        padding: 12px 10px !important;
        gap: 8px !important;
    }
    .chat-input-bar {
        padding: 8px 10px !important;
        gap: 6px !important;
    }
    .chat-input-bar button {
        width: 36px !important;
        height: 36px !important;
        flex-shrink: 0;
    }
    #chat-input-textarea {
        font-size: 15px !important;
        padding: 8px 10px !important;
    }
    #chat-new-group-modal > .card,
    #chat-group-info-modal > .card,
    #chat-add-members-modal > .card {
        width: 95% !important;
        max-width: 95% !important;
        max-height: 94vh !important;
        margin: auto !important;
    }
    #chat-new-group-modal,
    #chat-group-info-modal,
    #chat-add-members-modal,
    #chat-lightbox-modal {
        padding: 10px !important;
    }
}
</style>

<div class="chat-container">
    <!-- Chat Card Container -->
    <div class="card chat-card-wrapper">
        
        <!-- SOL PANEL: SOHBETLER VE REHBER -->
        <div id="chat-sidebar" class="chat-sidebar">
            
            <!-- Sidebar Header & Arama -->
            <div style="padding: 14px 16px; border-bottom: 1px solid var(--border-color);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-comments" style="color: var(--primary);"></i> Mesajlar
                    </h3>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <button type="button" class="btn btn-sm btn-outline-primary" style="padding: 2px 8px; font-size: 11.5px; border-radius: 8px; display: inline-flex; align-items: center; gap: 4px;" onclick="openNewGroupModal()" title="Yeni Grup Oluştur">
                            <i class="fas fa-users"></i> + Grup
                        </button>
                        <span id="chat-ws-status-badge" class="badge" style="font-size: 11px; padding: 3px 8px; background: rgba(0,0,0,0.05); color: var(--text-muted); border-radius: 10px;">
                            <i class="fas fa-circle" style="font-size: 8px; margin-right: 4px; color: var(--warning);"></i> Bağlanıyor...
                        </span>
                    </div>
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
        <div id="chat-main" class="chat-main">
            
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
                    <div style="display: flex; align-items: center; min-width: 0; flex: 1;">
                        <!-- Mobilde Geri Butonu -->
                        <button type="button" id="chat-mobile-back-btn" class="chat-mobile-back-btn" onclick="closeActiveChatMobile()" title="Geri">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div id="chat-header-info-btn" style="display: flex; align-items: center; gap: 12px; cursor: pointer; min-width: 0; flex: 1; overflow: hidden;" onclick="handleHeaderClick()">
                            <div id="active-target-avatar" style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; position: relative; flex-shrink: 0; overflow: visible;">
                                <span id="active-target-initial">U</span>
                                <span id="active-target-status-dot" style="position: absolute; bottom: 0; right: 0; width: 11px; height: 11px; border-radius: 50%; background: #9ca3af; border: 2px solid var(--bg-card);"></span>
                            </div>
                            <div style="min-width: 0; flex: 1; overflow: hidden;">
                                <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                                    <h4 id="active-target-name" style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Kullanıcı</h4>
                                    <span id="active-target-ext" class="badge" style="background: rgba(0,0,0,0.06); color: var(--text-muted); font-size: 11px; border-radius: 6px; padding: 2px 6px; flex-shrink: 0;">#0000</span>
                                </div>
                                <div id="active-target-status-text" style="font-size: 12px; color: var(--text-muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    Çevrimdışı
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hızlı İşlemler: Ara / Grup Bilgisi -->
                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                        <button id="active-target-call-btn" class="btn btn-sm btn-outline-primary" title="Dahiliyi Ara" style="border-radius: 8px; padding: 6px 12px;" onclick="callTargetExtension()">
                            <i class="fas fa-phone-alt"></i> <span class="d-none d-md-inline" style="margin-left: 4px;">Ara</span>
                        </button>
                        <button id="active-group-info-btn" class="btn btn-sm btn-outline-secondary" title="Grup Bilgisi" style="display: none; border-radius: 8px; padding: 6px 12px;" onclick="openGroupInfoModal()">
                            <i class="fas fa-info-circle"></i> <span class="d-none d-md-inline" style="margin-left: 4px;">Grup</span>
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
                <div class="chat-input-bar" style="padding: 12px 18px; background: var(--bg-card); border-top: 1px solid var(--border-color); display: flex; align-items: center; gap: 8px;">
                    <!-- Gizli Dosya Seçiciler -->
                    <input type="file" id="chat-file-input" style="display: none;" onchange="handleFileSelected(event, 'file')" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    <input type="file" id="chat-photo-input" style="display: none;" onchange="handleFileSelected(event, 'image')" accept="image/*">

                    <!-- Ek Butonları -->
                    <button type="button" class="btn btn-sm btn-icon" title="Dosya / Belge Ekle" style="border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-muted); border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;" onclick="document.getElementById('chat-file-input').click()">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-icon" title="Fotoğraf Gönder" style="border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-muted); border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;" onclick="document.getElementById('chat-photo-input').click()">
                        <i class="fas fa-camera"></i>
                    </button>

                    <!-- Metin Girdisi -->
                    <div style="flex: 1; position: relative; min-width: 0;">
                        <textarea id="chat-input-textarea" rows="1" placeholder="Bir mesaj yazın..." style="width: 100%; resize: none; max-height: 120px; padding: 9px 14px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 13.5px; outline: none; line-height: 1.4; box-sizing: border-box;" onkeydown="handleInputKeydown(event)" oninput="handleInputTyping()"></textarea>
                    </div>

                    <!-- Gönder Butonu -->
                    <button id="chat-send-btn" type="button" class="btn btn-primary btn-icon" title="Gönder" style="border-radius: 10px; width: 42px; height: 38px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;" onclick="sendMessage()">
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

<!-- Yeni Grup Modal -->
<div id="chat-new-group-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;" onclick="closeNewGroupModal()">
    <div class="card" style="width: 100%; max-width: 480px; max-height: 90vh; display: flex; flex-direction: column; background: var(--bg-card); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden; padding: 0;" onclick="event.stopPropagation()">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-users" style="color: var(--primary);"></i> Yeni Grup Oluştur
            </h4>
            <button type="button" class="btn btn-sm" style="border: none; background: transparent; color: var(--text-muted); font-size: 16px; cursor: pointer;" onclick="closeNewGroupModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px;">
            <!-- Avatar ve Grup Adı -->
            <div style="display: flex; align-items: center; gap: 14px;">
                <div id="new-group-avatar-preview" style="position: relative; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; cursor: pointer; flex-shrink: 0; overflow: hidden;" onclick="document.getElementById('new-group-avatar-file').click()" title="Grup Resmi Seç (Opsiyonel)">
                    <i class="fas fa-camera"></i>
                </div>
                <input type="file" id="new-group-avatar-file" style="display: none;" accept="image/*" onchange="handleNewGroupAvatarSelect(event)">
                <div style="flex: 1;">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">Grup Adı *</label>
                    <input type="text" id="new-group-title" placeholder="Grup konusunu veya adını girin..." maxlength="100" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 13.5px; outline: none;">
                </div>
            </div>

            <!-- Grup Açıklaması -->
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;">Açıklama (Opsiyonel)</label>
                <input type="text" id="new-group-desc" placeholder="Grup açıklaması..." maxlength="255" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 13px; outline: none;">
            </div>

            <!-- Üye Seçimi -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-size: 12px; font-weight: 600; color: var(--text-muted); margin: 0;">Katılımcıları Seçin (<span id="new-group-selected-count">0</span> seçildi)</label>
                </div>
                <div style="position: relative; margin-bottom: 8px;">
                    <i class="fas fa-search" style="position: absolute; left: 10px; top: 9px; color: var(--text-muted); font-size: 12px;"></i>
                    <input type="text" id="new-group-search-contacts" placeholder="Kişilerde filtrele..." style="width: 100%; padding: 6px 10px 6px 30px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 12.5px; outline: none;" oninput="filterNewGroupContacts(this.value)">
                </div>
                <div id="new-group-contacts-list" style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 4px; display: flex; flex-direction: column; gap: 2px;">
                    <!-- Kişiler dinamik listelenecek -->
                </div>
            </div>
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid var(--border-color); background: var(--bg-main); display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" class="btn btn-sm btn-secondary" onclick="closeNewGroupModal()">İptal</button>
            <button type="button" id="new-group-submit-btn" class="btn btn-sm btn-primary" onclick="submitCreateGroup()">
                <i class="fas fa-check"></i> Grubu Oluştur
            </button>
        </div>
    </div>
</div>

<!-- Grup Bilgisi Modal -->
<div id="chat-group-info-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;" onclick="closeGroupInfoModal()">
    <div class="card" style="width: 100%; max-width: 500px; max-height: 90vh; display: flex; flex-direction: column; background: var(--bg-card); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden; padding: 0;" onclick="event.stopPropagation()">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--text-main);">
                Grup Bilgisi
            </h4>
            <button type="button" class="btn btn-sm" style="border: none; background: transparent; color: var(--text-muted); font-size: 16px; cursor: pointer;" onclick="closeGroupInfoModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px;">
            <!-- Grup Başlık Kartı -->
            <div style="display: flex; align-items: center; gap: 14px; padding: 12px; background: var(--bg-main); border-radius: 10px;">
                <div id="group-info-avatar-box" style="width: 54px; height: 54px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; overflow: hidden;">
                    <i class="fas fa-users"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h4 id="group-info-title" style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main); word-break: break-word;">Grup</h4>
                        <button id="group-info-edit-btn" class="btn btn-sm btn-outline-secondary" style="display: none; padding: 1px 6px; font-size: 11px; border-radius: 6px;" onclick="promptEditGroupInfo()" title="Grup Adını / Açıklamasını Düzenle">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                    </div>
                    <p id="group-info-desc" style="margin: 4px 0 0 0; font-size: 12px; color: var(--text-muted); word-break: break-word;"></p>
                    <div id="group-info-meta" style="margin-top: 4px; font-size: 11px; color: var(--text-muted);"></div>
                </div>
            </div>

            <!-- Katılımcılar Başlığı & Üye Ekle Butonu -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 13px; font-weight: 700; color: var(--text-main);">
                        Katılımcılar (<span id="group-info-members-count">0</span>)
                    </span>
                    <button id="group-info-add-member-btn" class="btn btn-sm btn-outline-primary" style="display: none; padding: 3px 10px; font-size: 11.5px; border-radius: 6px;" onclick="openAddMembersModal()">
                        <i class="fas fa-user-plus"></i> Üye Ekle
                    </button>
                </div>
                <div id="group-info-members-list" style="display: flex; flex-direction: column; gap: 4px; max-height: 220px; overflow-y: auto; padding-right: 2px;">
                    <!-- Üye listesi dinamik render edilecek -->
                </div>
            </div>
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid var(--border-color); background: var(--bg-main); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <button id="group-info-delete-btn" type="button" class="btn btn-sm btn-outline-danger" style="display: none;" onclick="confirmDeleteGroup()">
                    <i class="fas fa-trash-alt"></i> Grubu Sil
                </button>
            </div>
            <div>
                <button type="button" class="btn btn-sm btn-danger" onclick="confirmLeaveGroup()">
                    <i class="fas fa-sign-out-alt"></i> Gruptan Ayrıl
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Üye Ekle Modal -->
<div id="chat-add-members-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; align-items: center; justify-content: center; padding: 20px;" onclick="closeAddMembersModal()">
    <div class="card" style="width: 100%; max-width: 420px; max-height: 80vh; display: flex; flex-direction: column; background: var(--bg-card); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden; padding: 0;" onclick="event.stopPropagation()">
        <div style="padding: 14px 18px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main);">
                Gruba Üye Ekle
            </h4>
            <button type="button" class="btn btn-sm" style="border: none; background: transparent; color: var(--text-muted); font-size: 15px; cursor: pointer;" onclick="closeAddMembersModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
            <div style="position: relative;">
                <i class="fas fa-search" style="position: absolute; left: 10px; top: 9px; color: var(--text-muted); font-size: 12px;"></i>
                <input type="text" id="add-members-search" placeholder="Kişilerde filtrele..." style="width: 100%; padding: 6px 10px 6px 30px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 12.5px; outline: none;" oninput="filterAddMembersContacts(this.value)">
            </div>
            <div id="add-members-contacts-list" style="max-height: 240px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 4px; display: flex; flex-direction: column; gap: 2px;">
                <!-- Eklenebilecek kişiler -->
            </div>
        </div>
        <div style="padding: 10px 18px; border-top: 1px solid var(--border-color); background: var(--bg-main); display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" class="btn btn-sm btn-secondary" onclick="closeAddMembersModal()">İptal</button>
            <button type="button" id="add-members-submit-btn" class="btn btn-sm btn-primary" onclick="submitAddMembers()">
                <i class="fas fa-user-plus"></i> Ekle
            </button>
        </div>
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
let currentConv = null;
let currentTargetExt = null;
let conversations = [];
let contacts = [];
let currentTab = 'convs';
let pendingUpload = null;
let newGroupAvatarUrl = '';
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
        if (data.extension === MY_EXT) {
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
async function openConversation(conv, pushHistory = true) {
    currentConvId = conv.id;
    currentConv = conv;
    currentTargetExt = conv.target_ext || '';
    const isGroup = conv.type === 'group';

    // Mobilde konuşma görünümüne geç
    const cardWrapper = document.querySelector('.chat-card-wrapper');
    if (cardWrapper) {
        cardWrapper.classList.add('is-chat-open');
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

    // Mesajları çek
    await loadMessages(conv.id);

    // Listeyi yeniden render et (seçili arkaplanı güncellemek için)
    renderConversationsList();

    // Masaüstünde textarea'ya odaklan (mobilde klavyenin hemen açılıp ekranı kapatmaması için sadece > 768px)
    if (window.innerWidth > 768) {
        document.getElementById('chat-input-textarea').focus();
    }
}

// Mobilde Aktif Sohbeti Kapatıp Listeye Dön
function closeActiveChatMobile(popHistory = true) {
    const cardWrapper = document.querySelector('.chat-card-wrapper');
    if (cardWrapper) {
        cardWrapper.classList.remove('is-chat-open');
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

// Tarayıcı / Android Donanım Geri Tuşu Dinleyicisi
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

    // Sistem mesajı görünümü
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

// 18. Grup Yönetimi ve Yardımcı Fonksiyonlar
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
                    ${escapeHtml(u.full_name)} <span style="color: var(--text-muted); font-size: 11px;">(#${escapeHtml(u.extension)})</span>
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
            headers: { 'Authorization': 'Bearer ' + CHAT_TOKEN },
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
                'Authorization': 'Bearer ' + CHAT_TOKEN,
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
            headers: { 'Authorization': 'Bearer ' + CHAT_TOKEN }
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

    // Detayları çekip render et
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

        const isMe = p.extension === MY_EXT;
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
                        ${escapeHtml(p.full_name)} ${isMe ? '<span style="color: var(--text-muted); font-size: 11px;">(Siz)</span>' : ''}
                    </div>
                    <div style="font-size: 11px; color: var(--text-muted);">
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
                'Authorization': 'Bearer ' + CHAT_TOKEN,
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
                    ${escapeHtml(u.full_name)} <span style="color: var(--text-muted); font-size: 11px;">(#${escapeHtml(u.extension)})</span>
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
                'Authorization': 'Bearer ' + CHAT_TOKEN,
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
                'Authorization': 'Bearer ' + CHAT_TOKEN,
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
                'Authorization': 'Bearer ' + CHAT_TOKEN,
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
                'Authorization': 'Bearer ' + CHAT_TOKEN,
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
                'Authorization': 'Bearer ' + CHAT_TOKEN,
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
</script>
