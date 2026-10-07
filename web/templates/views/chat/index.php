<?php
/**
 * AI-PBX Chat & Media Sharing View
 */
$ext = $ext ?? '';
$user = $user ?? [];
$token = $token ?? '';
?>

<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/chat.css'); ?>">

<div class="chat-container">
    <!-- Top header & action card -->
    <div class="card chat-page-header">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; width: 100%;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(0, 242, 254, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                    <i class="fas fa-comments"></i>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <h2 class="u-fs-16 u-fw-700 u-m-0 u-text-main">
                            <?php echo t('chat.messages'); ?>
                        </h2>
                        <?php if (!empty($ext)): ?>
                            <span class="badge" style="background: rgba(0, 242, 254, 0.12); color: var(--primary); font-size: 12px; border-radius: 12px; padding: 2px 8px; font-weight: 700;">
                                #<?php echo htmlspecialchars($ext); ?> - <?php echo htmlspecialchars($user['full_name'] ?? $user['username'] ?? ''); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="u-fs-12 u-muted u-mt-2">
                        <?php echo t('chat.subtitle'); ?>
                    </div>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span id="chat-ws-status-badge" class="badge" style="font-size: 12px; padding: 6px 12px; background: rgba(0,0,0,0.05); color: var(--text-muted); border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center;">
                    <i class="fas fa-circle" style="font-size: 8px; margin-right: 6px; color: var(--warning);"></i> <?php echo t('chat.connecting'); ?>
                </span>
                <button type="button" class="btn btn-primary btn-sm" onclick="openNewGroupModal()" title="<?php echo t('chat.new_group'); ?>" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 8px; font-weight: 600;">
                    <i class="fas fa-users"></i> <?php echo t('chat.new_group_short'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Chat Card Container -->
    <div class="card chat-card-wrapper">
        
        <!-- SOL PANEL: SOHBETLER VE REHBER -->
        <div id="chat-sidebar" class="chat-sidebar">
            
            <!-- Sidebar Header & Arama -->
            <div class="chat-sidebar-header">
                <!-- Tabs: Chats / Contacts -->
                <div class="chat-sidebar-tabs">
                    <button id="tab-btn-convs" class="btn btn-sm chat-sidebar-tab-btn" style="background: var(--bg-card); color: var(--text-main); box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onclick="switchChatTab('convs')">
                        <i class="fas fa-comment-dots"></i> <?php echo t('chat.chats'); ?>
                    </button>
                    <button id="tab-btn-contacts" class="btn btn-sm chat-sidebar-tab-btn" style="background: transparent; color: var(--text-muted);" onclick="switchChatTab('contacts')">
                        <i class="fas fa-address-book"></i> <?php echo t('chat.directory'); ?>
                    </button>
                </div>

                <!-- Arama Kutusu -->
                <div class="chat-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="chat-search-input" placeholder="<?php echo t('chat.search_ph'); ?>" oninput="filterChatList(this.value)">
                </div>
            </div>

            <!-- List: conversations / directory -->
            <div id="chat-list-container" style="flex: 1; overflow-y: auto; padding: 6px;">
                <div id="chat-list-loading" style="text-align: center; padding: 30px; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin u-fs-20"></i>
                    <div style="margin-top: 8px; font-size: 12px;"><?php echo t('chat.loading'); ?></div>
                </div>
                <div id="chat-convs-list" style="display: flex; flex-direction: column; gap: 2px;"></div>
                <div id="chat-contacts-list" style="display: none; flex-direction: column; gap: 2px;"></div>
            </div>

            <!-- The user's own info bar at the bottom -->
            <div style="padding: 10px 14px; border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; background: var(--bg-main); font-size: 12px;">
                <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0;">
                        <?php echo strtoupper(mb_substr($user['full_name'] ?? $user['username'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <span class="u-fw-600 u-text-main"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></span>
                        <span class="u-muted u-fs-11">(#<?php echo htmlspecialchars($ext); ?>)</span>
                    </div>
                </div>
                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; font-size: 11px; padding: 3px 8px; border-radius: 8px;">
                    <?php echo t('common.active'); ?>
                </span>
            </div>
        </div>

        <!-- RIGHT PANEL: ACTIVE CHAT WINDOW -->
        <div id="chat-main" class="chat-main">
            
            <!-- Empty state (no chat selected) -->
            <div id="chat-empty-state" style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-muted); padding: 30px; text-align: center;">
                <div style="width: 80px; height: 80px; border-radius: 50%; background: rgba(0, 242, 254, 0.08); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 34px; margin-bottom: 16px;">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <h4 style="margin: 0 0 8px 0; color: var(--text-main); font-size: 18px; font-weight: 700;"><?php echo t('chat.welcome_title'); ?></h4>
                <p style="margin: 0; max-width: 360px; font-size: 13.5px; line-height: 1.5;">
                    <?php echo t('chat.welcome_text'); ?>
                </p>
                <button class="btn btn-primary btn-sm" style="margin-top: 20px; border-radius: 20px; padding: 8px 20px;" onclick="switchChatTab('contacts')">
                    <i class="fas fa-user-plus"></i> <?php echo t('chat.browse_directory'); ?>
                </button>
            </div>

            <!-- Active conversation interface -->
            <div id="chat-active-pane" style="display: none; flex: 1; flex-direction: column; height: 100%; overflow: hidden;">
                
                <!-- Active chat header -->
                <div id="chat-header" style="padding: 12px 18px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; min-width: 0; flex: 1;">
                        <!-- Mobilde Geri Butonu -->
                        <button type="button" id="chat-mobile-back-btn" class="chat-mobile-back-btn" onclick="closeActiveChatMobile()" title="<?php echo t('common.back'); ?>">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div id="chat-header-info-btn" style="display: flex; align-items: center; gap: 12px; cursor: pointer; min-width: 0; flex: 1; overflow: hidden;" onclick="handleHeaderClick()">
                            <div id="active-target-avatar" style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; position: relative; flex-shrink: 0; overflow: visible;">
                                <span id="active-target-initial">U</span>
                                <span id="active-target-status-dot" style="position: absolute; bottom: 0; right: 0; width: 11px; height: 11px; border-radius: 50%; background: #9ca3af; border: 2px solid var(--bg-card);"></span>
                            </div>
                            <div style="min-width: 0; flex: 1; overflow: hidden;">
                                <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                                    <h4 id="active-target-name" style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo t('chat.user'); ?></h4>
                                    <span id="active-target-ext" class="badge" style="background: rgba(0,0,0,0.06); color: var(--text-muted); font-size: 11px; border-radius: 6px; padding: 2px 6px; flex-shrink: 0;">#0000</span>
                                </div>
                                <div id="active-target-status-text" style="font-size: 12px; color: var(--text-muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo t('chat.offline'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick actions: call / group info -->
                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                        <button id="active-target-call-btn" class="btn btn-sm btn-outline-primary" title="<?php echo t('chat.call_ext'); ?>" style="border-radius: 8px; padding: 6px 12px;" onclick="callTargetExtension()">
                            <i class="fas fa-phone-alt"></i> <span class="d-none d-md-inline" style="margin-left: 4px;"><?php echo t('chat.call'); ?></span>
                        </button>
                        <button id="active-group-info-btn" class="btn btn-sm btn-outline-secondary" title="<?php echo t('chat.group_info'); ?>" style="display: none; border-radius: 8px; padding: 6px 12px;" onclick="openGroupInfoModal()">
                            <i class="fas fa-info-circle"></i> <span class="d-none d-md-inline" style="margin-left: 4px;"><?php echo t('chat.group'); ?></span>
                        </button>
                    </div>
                </div>

                <!-- Message stream -->
                <div id="chat-messages-scroll" style="flex: 1; overflow-y: auto; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px;">
                    <!-- Mesajlar dinamik eklenecek -->
                </div>

                <!-- Typing indicator -->
                <div id="chat-typing-indicator" style="display: none; padding: 4px 20px; font-size: 12px; color: var(--text-muted); font-style: italic;">
                    <i class="fas fa-ellipsis-h fa-bounce u-mr-4"></i> <span id="chat-typing-text"><?php echo t('chat.typing'); ?></span>
                </div>

                <!-- File / image upload preview bar -->
                <div id="chat-upload-preview-bar" style="display: none; padding: 8px 16px; background: rgba(0, 242, 254, 0.08); border-top: 1px solid var(--border-color); align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--text-main);">
                        <i class="fas fa-cloud-upload-alt u-primary"></i>
                        <span id="chat-upload-filename" class="u-fw-600">dosya.png</span>
                        <span id="chat-upload-filesize" class="u-muted">(0 KB)</span>
                    </div>
                    <button class="btn btn-sm" style="border: none; background: transparent; color: var(--danger);" onclick="cancelUploadPreview()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Message input box -->
                <div class="chat-input-bar" style="padding: 12px 18px; background: var(--bg-card); border-top: 1px solid var(--border-color); display: flex; align-items: center; gap: 8px;">
                    <!-- Hidden file pickers -->
                    <input type="file" id="chat-file-input" style="display: none;" onchange="handleFileSelected(event, 'file')" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    <input type="file" id="chat-photo-input" style="display: none;" onchange="handleFileSelected(event, 'image')" accept="image/*">

                    <!-- Attachment buttons -->
                    <button type="button" class="btn btn-sm btn-icon" title="<?php echo t('chat.attach'); ?>" style="border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-muted); border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;" onclick="document.getElementById('chat-file-input').click()">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-icon" title="<?php echo t('chat.send_photo'); ?>" style="border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-muted); border-radius: 8px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;" onclick="document.getElementById('chat-photo-input').click()">
                        <i class="fas fa-camera"></i>
                    </button>

                    <!-- Metin Girdisi -->
                    <div style="flex: 1; position: relative; min-width: 0;">
                        <textarea id="chat-input-textarea" rows="1" placeholder="<?php echo t('chat.message_ph'); ?>" style="width: 100%; resize: none; max-height: 120px; padding: 9px 14px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 13.5px; outline: none; line-height: 1.4; box-sizing: border-box;" onkeydown="handleInputKeydown(event)" oninput="handleInputTyping()"></textarea>
                    </div>

                    <!-- Send button -->
                    <button id="chat-send-btn" type="button" class="btn btn-primary btn-icon" title="<?php echo t('chat.send'); ?>" style="border-radius: 10px; width: 42px; height: 38px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;" onclick="sendMessage()">
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
            <i class="fas fa-download"></i> <?php echo t('common.download'); ?>
        </a>
    </div>
</div>

<!-- Yeni Grup Modal -->
<div id="chat-new-group-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;" onclick="closeNewGroupModal()">
    <div class="card" style="width: 100%; max-width: 480px; max-height: 90vh; display: flex; flex-direction: column; background: var(--bg-card); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden; padding: 0;" onclick="event.stopPropagation()">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-users u-primary"></i> <?php echo t('chat.new_group'); ?>
            </h4>
            <button type="button" class="btn btn-sm" style="border: none; background: transparent; color: var(--text-muted); font-size: 16px; cursor: pointer;" onclick="closeNewGroupModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px;">
            <!-- Avatar and group name -->
            <div style="display: flex; align-items: center; gap: 14px;">
                <div id="new-group-avatar-preview" style="position: relative; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; cursor: pointer; flex-shrink: 0; overflow: hidden;" onclick="document.getElementById('new-group-avatar-file').click()" title="<?php echo t('chat.group_avatar'); ?>">
                    <i class="fas fa-camera"></i>
                </div>
                <input type="file" id="new-group-avatar-file" style="display: none;" accept="image/*" onchange="handleNewGroupAvatarSelect(event)">
                <div class="u-flex-1">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;"><?php echo t('chat.group_name_req'); ?></label>
                    <input type="text" id="new-group-title" placeholder="<?php echo t('chat.group_name_ph'); ?>" maxlength="100" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 13.5px; outline: none;">
                </div>
            </div>

            <!-- Group description -->
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px;"><?php echo t('chat.desc_optional'); ?></label>
                <input type="text" id="new-group-desc" placeholder="<?php echo t('chat.group_desc_ph'); ?>" maxlength="255" style="width: 100%; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 13px; outline: none;">
            </div>

            <!-- Member selection -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="u-fs-12 u-fw-600 u-muted u-m-0"><?php echo t('chat.select_members'); ?> (<span id="new-group-selected-count">0</span> <?php echo t('chat.selected'); ?>)</label>
                </div>
                <div class="u-relative u-mb-8">
                    <i class="fas fa-search" style="position: absolute; left: 10px; top: 9px; color: var(--text-muted); font-size: 12px;"></i>
                    <input type="text" id="new-group-search-contacts" placeholder="<?php echo t('chat.filter_contacts_ph'); ?>" style="width: 100%; padding: 6px 10px 6px 30px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 12.5px; outline: none;" oninput="filterNewGroupContacts(this.value)">
                </div>
                <div id="new-group-contacts-list" style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 4px; display: flex; flex-direction: column; gap: 2px;">
                    <!-- Contacts are listed dynamically -->
                </div>
            </div>
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid var(--border-color); background: var(--bg-main); display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" class="btn btn-sm btn-secondary" onclick="closeNewGroupModal()"><?php echo t('common.cancel'); ?></button>
            <button type="button" id="new-group-submit-btn" class="btn btn-sm btn-primary" onclick="submitCreateGroup()">
                <i class="fas fa-check"></i> <?php echo t('chat.create_group'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Grup Bilgisi Modal -->
<div id="chat-group-info-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;" onclick="closeGroupInfoModal()">
    <div class="card" style="width: 100%; max-width: 500px; max-height: 90vh; display: flex; flex-direction: column; background: var(--bg-card); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden; padding: 0;" onclick="event.stopPropagation()">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <h4 class="u-m-0 u-fs-16 u-fw-700 u-text-main">
                <?php echo t('chat.group_info'); ?>
            </h4>
            <button type="button" class="btn btn-sm" style="border: none; background: transparent; color: var(--text-muted); font-size: 16px; cursor: pointer;" onclick="closeGroupInfoModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px;">
            <!-- Group header card -->
            <div style="display: flex; align-items: center; gap: 14px; padding: 12px; background: var(--bg-main); border-radius: 10px;">
                <div id="group-info-avatar-box" style="width: 54px; height: 54px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; overflow: hidden;">
                    <i class="fas fa-users"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div class="u-flex-center">
                        <h4 id="group-info-title" style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main); word-break: break-word;"><?php echo t('chat.group'); ?></h4>
                        <button id="group-info-edit-btn" class="btn btn-sm btn-outline-secondary" style="display: none; padding: 1px 6px; font-size: 11px; border-radius: 6px;" onclick="promptEditGroupInfo()" title="<?php echo t('chat.edit_group'); ?>">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                    </div>
                    <p id="group-info-desc" style="margin: 4px 0 0 0; font-size: 12px; color: var(--text-muted); word-break: break-word;"></p>
                    <div id="group-info-meta" class="u-muted u-fs-11 u-mt-4"></div>
                </div>
            </div>

            <!-- Participants header & add-member button -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span class="u-fs-13 u-fw-700 u-text-main">
                        <?php echo t('chat.participants'); ?> (<span id="group-info-members-count">0</span>)
                    </span>
                    <button id="group-info-add-member-btn" class="btn btn-sm btn-outline-primary" style="display: none; padding: 3px 10px; font-size: 11.5px; border-radius: 6px;" onclick="openAddMembersModal()">
                        <i class="fas fa-user-plus"></i> <?php echo t('chat.add_member'); ?>
                    </button>
                </div>
                <div id="group-info-members-list" style="display: flex; flex-direction: column; gap: 4px; max-height: 220px; overflow-y: auto; padding-right: 2px;">
                    <!-- The member list is rendered dynamically -->
                </div>
            </div>
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid var(--border-color); background: var(--bg-main); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <button id="group-info-delete-btn" type="button" class="btn btn-sm btn-outline-danger" style="display: none;" onclick="confirmDeleteGroup()">
                    <i class="fas fa-trash-alt"></i> <?php echo t('chat.delete_group'); ?>
                </button>
            </div>
            <div>
                <button type="button" class="btn btn-sm btn-danger" onclick="confirmLeaveGroup()">
                    <i class="fas fa-sign-out-alt"></i> <?php echo t('chat.leave_group'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add member modal -->
<div id="chat-add-members-modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 10000; align-items: center; justify-content: center; padding: 20px;" onclick="closeAddMembersModal()">
    <div class="card" style="width: 100%; max-width: 420px; max-height: 80vh; display: flex; flex-direction: column; background: var(--bg-card); border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); overflow: hidden; padding: 0;" onclick="event.stopPropagation()">
        <div style="padding: 14px 18px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main);">
                <?php echo t('chat.add_members_title'); ?>
            </h4>
            <button type="button" class="btn btn-sm" style="border: none; background: transparent; color: var(--text-muted); font-size: 15px; cursor: pointer;" onclick="closeAddMembersModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
            <div class="u-relative">
                <i class="fas fa-search" style="position: absolute; left: 10px; top: 9px; color: var(--text-muted); font-size: 12px;"></i>
                <input type="text" id="add-members-search" placeholder="<?php echo t('chat.filter_contacts_ph'); ?>" style="width: 100%; padding: 6px 10px 6px 30px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-main); color: var(--text-main); font-size: 12.5px; outline: none;" oninput="filterAddMembersContacts(this.value)">
            </div>
            <div id="add-members-contacts-list" style="max-height: 240px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 4px; display: flex; flex-direction: column; gap: 2px;">
                <!-- Contacts that can be added -->
            </div>
        </div>
        <div style="padding: 10px 18px; border-top: 1px solid var(--border-color); background: var(--bg-main); display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" class="btn btn-sm btn-secondary" onclick="closeAddMembersModal()"><?php echo t('common.cancel'); ?></button>
            <button type="button" id="add-members-submit-btn" class="btn btn-sm btn-primary" onclick="submitAddMembers()">
                <i class="fas fa-user-plus"></i> <?php echo t('chat.add'); ?>
            </button>
        </div>
    </div>
</div>

<script>
// Page data for assets/js/chat.js (window assignments survive SPA navigation)
window.CHAT_TOKEN = <?= json_encode($token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MY_EXT = <?= json_encode($ext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.MY_NAME = <?= json_encode($user['full_name'] ?? $user['username'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?php echo asset('/assets/js/chat.js'); ?>"></script>
