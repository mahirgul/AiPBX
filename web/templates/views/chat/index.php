<?php
/**
 * AI-PBX Chat & Media Sharing View
 */
$ext = $ext ?? '';
$user = $user ?? [];
$token = $token ?? '';
$is_admin = !empty($is_admin);
$delete_window = (int) ($delete_window ?? 0);
?>

<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/chat.css'); ?>">

<div class="chat-container">
    <!-- Top header & action card -->
    <div class="card page-header-card chat-page-header">
        <?php echo uiPageHeaderOpen('fa-comments', t('chat.messages'), 'chatHelpBox'); ?>
<?php if (!empty($ext)): ?>
                            <span class="badge u-bg-rgba-0-242-254-0-12 u-primary u-fs-12 u-r-12 u-p-2-8 u-fw-700">
                                #<?php echo htmlspecialchars($ext); ?> - <?php echo htmlspecialchars($user['full_name'] ?? $user['username'] ?? ''); ?>
                            </span>
                        <?php endif; ?>
<span id="chat-ws-status-badge" class="badge u-fs-12 u-p-8-12 u-bg-rgba-0-0-0-0-05 u-muted u-r-999 u-fw-600 u-ai-center" style="display: inline-flex;">
                    <i class="fas fa-circle u-fs-10 u-mr-8 u-warning"></i> <?php echo t('chat.connecting'); ?>
                </span>
<?php echo uiPageHeaderActions(); ?>
                <button type="button" class="btn btn-primary btn-sm u-d-inline-flex u-ai-center u-gap-8 u-r-8 u-fw-600" onclick="openNewGroupModal()" title="<?php echo t('chat.new_group'); ?>">
                    <i class="fas fa-users"></i> <?php echo t('chat.new_group_short'); ?>
                </button>
<?php echo uiPageHeaderClose(); ?>
<?php echo uiHelpBoxOpen('chatHelpBox', t('common.module_guide')); ?><?php echo t('chat.subtitle'); ?><?php echo uiHelpBoxClose(); ?>
        <?php if ($is_admin): ?>
            <details class="chat-settings-panel">
                <summary><i class="fas fa-cog"></i> <?php echo t('chat.settings_title'); ?></summary>
                <form method="post" action="/chat" class="chat-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                    <label for="chat-delete-window"><?php echo t('chat.settings_delete_window'); ?></label>
                    <input type="number" id="chat-delete-window" name="chat_delete_window_minutes" class="form-control" min="0" max="<?php echo ChatSettingsService::DELETE_WINDOW_MAX; ?>" step="1" value="<?php echo $delete_window; ?>" required>
                    <span class="u-muted"><?php echo t('chat.settings_delete_window_help'); ?></span>
                    <button type="submit" name="save_chat_settings" value="1" class="btn btn-primary btn-sm"><?php echo t('common.save'); ?></button>
                </form>
            </details>
        <?php endif; ?>
    </div>

    <!-- Chat Card Container -->
    <div class="card chat-card-wrapper">
        
        <!-- LEFT PANEL: CONVERSATIONS AND DIRECTORY -->
        <div id="chat-sidebar" class="chat-sidebar">
            
            <!-- Sidebar header & search -->
            <div class="chat-sidebar-header">
                <!-- Tabs: Chats / Directory (the shared tab row; the lists are below the search box) -->
                <?php echo uiTabs([
                    'convs' => ['label' => t('chat.chats'), 'icon' => 'fa-comment-dots', 'onclick' => "switchChatTab('convs')"],
                    'contacts' => ['label' => t('chat.directory'), 'icon' => 'fa-address-book', 'onclick' => "switchChatTab('contacts')"],
                ], 'convs'); ?>

                <!-- Search box -->
                <div class="chat-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="chat-search-input" placeholder="<?php echo t('chat.search_ph'); ?>" oninput="filterChatList(this.value)">
                </div>
            </div>

            <!-- List: conversations / directory -->
            <div class="u-flex-1 u-overflow-y-auto u-p-8" id="chat-list-container">
                <div id="chat-list-loading" class="u-ta-center u-p-30 u-muted">
                    <i class="fas fa-spinner fa-spin u-fs-20"></i>
                    <div class="u-mt-8 u-fs-12"><?php echo t('chat.loading'); ?></div>
                </div>
                <div id="chat-convs-list" class="u-d-flex u-col u-gap-2"></div>
                <div class="u-col u-gap-2" id="chat-contacts-list" style="display: none;"></div>
            </div>

            <!-- The user's own info bar at the bottom -->
            <div class="u-p-12-16 u-border-top-1-solid-border-color u-d-flex u-ai-center u-jc-space-between u-bg-bg-main u-fs-12">
                <div class="u-d-flex u-ai-center u-gap-8 u-of-hidden">
                    <div class="u-w-28 u-h-28 u-r-50pc u-bg-primary u-c-fff u-d-flex u-ai-center u-jc-center u-fw-700 u-fs-12 u-flex-shrink-0">
                        <?php echo strtoupper(mb_substr($user['full_name'] ?? $user['username'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="u-of-hidden u-text-overflow-ellipsis u-nowrap">
                        <span class="u-fw-600 u-text-main"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></span>
                        <span class="u-muted u-fs-11">(#<?php echo htmlspecialchars($ext); ?>)</span>
                    </div>
                </div>
                <span class="badge u-bg-rgba-16-185-129-0-15 u-c-10b981 u-fs-11 u-p-4-8 u-r-8">
                    <?php echo t('common.active'); ?>
                </span>
            </div>
        </div>

        <!-- RIGHT PANEL: ACTIVE CHAT WINDOW -->
        <div id="chat-main" class="chat-main">
            
            <!-- Empty state (no chat selected) -->
            <div id="chat-empty-state" class="u-flex-1 u-d-flex u-col u-ai-center u-jc-center u-muted u-p-30 u-ta-center">
                <div class="u-w-80 u-h-80 u-r-50pc u-bg-rgba-0-242-254-0-08 u-primary u-d-flex u-ai-center u-jc-center u-fs-32 u-mb-16">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <h4 class="u-m-0-0-8-0 u-text-main u-fs-18 u-fw-700"><?php echo t('chat.welcome_title'); ?></h4>
                <p class="u-m-0 u-maxw-360 u-fs-14 u-lh-1-5">
                    <?php echo t('chat.welcome_text'); ?>
                </p>
                <button class="btn btn-primary btn-sm u-mt-20 u-r-999" onclick="switchChatTab('contacts')">
                    <i class="fas fa-user-plus"></i> <?php echo t('chat.browse_directory'); ?>
                </button>
            </div>

            <!-- Active conversation interface -->
            <div class="u-flex-1 u-col u-h-100pc u-of-hidden" id="chat-active-pane" style="display: none;">
                
                <!-- Active chat header -->
                <div class="u-p-12-20 u-bg-bg-card u-border-bottom-1-solid-border-color u-ai-center u-jc-space-between" id="chat-header" style="display: flex;">
                    <div class="u-d-flex u-ai-center u-minw-0 u-flex-1">
                        <!-- Back button on mobile -->
                        <button type="button" id="chat-mobile-back-btn" class="chat-mobile-back-btn" onclick="closeActiveChatMobile()" title="<?php echo t('common.back'); ?>">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="u-ai-center u-gap-12 u-pointer u-minw-0 u-flex-1 u-of-hidden" id="chat-header-info-btn" style="display: flex;" onclick="handleHeaderClick()">
                            <div class="u-w-40 u-h-40 u-r-50pc u-bg-primary u-c-fff u-ai-center u-jc-center u-fw-700 u-fs-16 u-relative u-flex-shrink-0 u-of-visible" id="active-target-avatar" style="display: flex;">
                                <span id="active-target-initial">U</span>
                                <span class="u-pos-absolute u-bottom-0 u-right-0 u-w-11 u-h-11 u-r-50pc u-bg-9ca3af u-bd-2-solid-bg-card" id="active-target-status-dot"></span>
                            </div>
                            <div class="u-minw-0 u-flex-1 u-of-hidden">
                                <div class="u-d-flex u-ai-center u-gap-8 u-of-hidden">
                                    <h4 class="u-m-0 u-fs-16 u-fw-700 u-text-main u-nowrap u-of-hidden u-text-overflow-ellipsis" id="active-target-name"><?php echo t('chat.user'); ?></h4>
                                    <span id="active-target-ext" class="badge u-bg-rgba-0-0-0-0-06 u-muted u-fs-11 u-r-8 u-p-2-8 u-flex-shrink-0">#0000</span>
                                </div>
                                <div class="u-fs-12 u-muted u-mt-2 u-nowrap u-of-hidden u-text-overflow-ellipsis" id="active-target-status-text">
                                    <?php echo t('chat.offline'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick actions: call / group info -->
                    <div class="u-d-flex u-ai-center u-gap-8 u-flex-shrink-0">
                        <button id="active-target-call-btn" class="btn btn-sm btn-outline-primary u-r-8" title="<?php echo t('chat.call_ext'); ?>" onclick="callTargetExtension()">
                            <i class="fas fa-phone-alt"></i> <span class="d-none d-md-inline u-ml-4"><?php echo t('chat.call'); ?></span>
                        </button>
                        <button id="active-group-voice-btn" class="btn btn-sm btn-outline-primary u-r-8" title="<?php echo t('chat.group_voice_call'); ?>" style="display: none;" onclick="startChatCall(false)">
                            <i class="fas fa-phone-alt"></i>
                        </button>
                        <button id="active-group-video-btn" class="btn btn-sm btn-outline-primary u-r-8" title="<?php echo t('chat.group_video_call'); ?>" style="display: none;" onclick="startChatCall(true)">
                            <i class="fas fa-video"></i>
                        </button>
                        <button id="active-group-info-btn" class="btn btn-sm btn-outline-secondary u-r-8" title="<?php echo t('chat.group_info'); ?>" style="display: none;" onclick="openGroupInfoModal()">
                            <i class="fas fa-info-circle"></i> <span class="d-none d-md-inline u-ml-4"><?php echo t('chat.group'); ?></span>
                        </button>
                    </div>
                </div>

                <!-- Message stream -->
                <div class="u-flex-1 u-overflow-y-auto u-p-20-20 u-col u-gap-12" id="chat-messages-scroll" style="display: flex;">
                    <!-- Messages are added by chat.js -->
                </div>

                <!-- Typing indicator -->
                <div class="u-p-4-20 u-fs-12 u-muted u-font-style-italic" id="chat-typing-indicator" style="display: none;">
                    <i class="fas fa-ellipsis-h fa-bounce u-mr-4"></i> <span id="chat-typing-text"><?php echo t('chat.typing'); ?></span>
                </div>

                <!-- File / image upload preview bar -->
                <div class="u-p-8-16 u-bg-rgba-0-242-254-0-08 u-border-top-1-solid-border-color u-ai-center u-jc-space-between" id="chat-upload-preview-bar" style="display: none;">
                    <div class="u-d-flex u-ai-center u-gap-12 u-fs-13 u-text-main">
                        <i class="fas fa-cloud-upload-alt u-primary"></i>
                        <span id="chat-upload-filename" class="u-fw-600">dosya.png</span>
                        <span id="chat-upload-filesize" class="u-muted">(0 KB)</span>
                    </div>
                    <button class="btn btn-icon btn-sm u-bd-none u-bg-transparent u-danger" onclick="cancelUploadPreview()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Message input box -->
                <div class="chat-input-bar u-p-12-20 u-bg-bg-card u-border-top-1-solid-border-color u-d-flex u-ai-center u-gap-8">
                    <!-- Hidden file pickers -->
                    <input type="file" id="chat-file-input" style="display: none;" onchange="handleFileSelected(event, 'file')" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    <input type="file" id="chat-photo-input" style="display: none;" onchange="handleFileSelected(event, 'image')" accept="image/*">

                    <!-- Attachment buttons -->
                    <button type="button" class="btn btn-sm btn-icon u-bd-1-solid-border-color u-bg-bg-main u-muted u-r-8 u-w-38 u-d-flex u-ai-center u-jc-center u-flex-shrink-0" title="<?php echo t('chat.attach'); ?>" onclick="document.getElementById('chat-file-input').click()">
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-icon u-bd-1-solid-border-color u-bg-bg-main u-muted u-r-8 u-w-38 u-d-flex u-ai-center u-jc-center u-flex-shrink-0" title="<?php echo t('chat.send_photo'); ?>" onclick="document.getElementById('chat-photo-input').click()">
                        <i class="fas fa-camera"></i>
                    </button>

                    <!-- Text input -->
                    <div class="u-flex-1 u-relative u-minw-0">
                        <textarea class="u-w-100 u-resize-none u-maxh-120 u-p-8-16 u-r-12 u-bd-1-solid-border-color u-bg-bg-main u-text-main u-fs-14 u-outline-none u-lh-1-4 u-box-sizing-border-box" id="chat-input-textarea" rows="1" placeholder="<?php echo t('chat.message_ph'); ?>" onkeydown="handleInputKeydown(event)" oninput="handleInputTyping()"></textarea>
                    </div>

                    <!-- Send button -->
                    <button id="chat-send-btn" type="button" class="btn btn-primary btn-icon u-r-12 u-w-42 u-ai-center u-jc-center u-flex-shrink-0" title="<?php echo t('chat.send'); ?>" style="display: flex;" onclick="sendMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Full-screen image lightbox modal -->
<div class="u-pos-fixed u-inset-0 u-bg-rgba-0-0-0-0-85 u-z-index-99999 u-ai-center u-jc-center u-p-20" id="chat-lightbox-modal" style="display: none;" onclick="closeLightbox()">
    <div class="u-relative u-maxw-90vw u-maxh-90vh">
        <img class="u-maxw-100pc u-maxh-85vh u-r-8 u-box-shadow-0-10-40-rgba-0-0-0-0-5" id="chat-lightbox-img" src="" onclick="event.stopPropagation()">
        <a class="u-pos-absolute u-top-10 u-right-10 u-bg-rgba-0-0-0-0-6 u-c-fff u-p-8-16 u-r-8 u-fs-13 u-text-decoration-none" id="chat-lightbox-download" href="" target="_blank" download onclick="event.stopPropagation()">
            <i class="fas fa-download"></i> <?php echo t('common.download'); ?>
        </a>
    </div>
</div>

<!-- New group modal -->
<div class="u-pos-fixed u-inset-0 u-bg-rgba-0-0-0-0-6 u-z-index-9999 u-ai-center u-jc-center u-p-20" id="chat-new-group-modal" style="display: none;" onclick="closeNewGroupModal()">
    <div class="card u-w-100 u-maxw-480 u-maxh-90vh u-d-flex u-col u-bg-bg-card u-r-12 u-box-shadow-0-10-40-rgba-0-0-0-0-25 u-of-hidden u-p-0" onclick="event.stopPropagation()">
        <div class="u-p-16-20 u-border-bottom-1-solid-border-color u-d-flex u-ai-center u-jc-space-between">
            <h4 class="u-m-0 u-fs-16 u-fw-700 u-text-main u-d-flex u-ai-center u-gap-8">
                <i class="fas fa-users u-primary"></i> <?php echo t('chat.new_group'); ?>
            </h4>
            <button type="button" class="btn btn-icon btn-sm u-bd-none u-bg-transparent u-muted u-pointer" onclick="closeNewGroupModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="u-p-20 u-overflow-y-auto u-d-flex u-col u-gap-16">
            <!-- Avatar and group name -->
            <div class="u-d-flex u-ai-center u-gap-14">
                <div class="u-relative u-w-60 u-h-60 u-r-50pc u-bg-linear-gradient-135deg-6366f1-8b5cf6 u-c-fff u-ai-center u-jc-center u-fs-24 u-pointer u-flex-shrink-0 u-of-hidden" id="new-group-avatar-preview" style="display: flex;" onclick="document.getElementById('new-group-avatar-file').click()" title="<?php echo t('chat.group_avatar'); ?>">
                    <i class="fas fa-camera"></i>
                </div>
                <input type="file" id="new-group-avatar-file" style="display: none;" accept="image/*" onchange="handleNewGroupAvatarSelect(event)">
                <div class="u-flex-1">
                    <label class="u-fs-12 u-fw-600 u-muted u-mb-4" style="display: block;"><?php echo t('chat.group_name_req'); ?></label>
                    <input class="u-w-100 u-p-8-12 u-r-8 u-bd-1-solid-border-color u-bg-bg-main u-text-main u-fs-14 u-outline-none" type="text" id="new-group-title" placeholder="<?php echo t('chat.group_name_ph'); ?>" maxlength="100">
                </div>
            </div>

            <!-- Group description -->
            <div>
                <label class="u-fs-12 u-fw-600 u-muted u-mb-4" style="display: block;"><?php echo t('chat.desc_optional'); ?></label>
                <input class="u-w-100 u-p-8-12 u-r-8 u-bd-1-solid-border-color u-bg-bg-main u-text-main u-fs-13 u-outline-none" type="text" id="new-group-desc" placeholder="<?php echo t('chat.group_desc_ph'); ?>" maxlength="255">
            </div>

            <!-- Member selection -->
            <div>
                <div class="u-d-flex u-jc-space-between u-ai-center u-mb-6">
                    <label class="u-fs-12 u-fw-600 u-muted u-m-0"><?php echo t('chat.select_members'); ?> (<span id="new-group-selected-count">0</span> <?php echo t('chat.selected'); ?>)</label>
                </div>
                <div class="u-relative u-mb-8">
                    <i class="fas fa-search u-pos-absolute u-left-10 u-top-9 u-muted u-fs-12"></i>
                    <input class="u-w-100 u-p-8-12-8-32 u-r-8 u-bd-1-solid-border-color u-bg-bg-main u-text-main u-fs-13 u-outline-none" type="text" id="new-group-search-contacts" placeholder="<?php echo t('chat.filter_contacts_ph'); ?>" oninput="filterNewGroupContacts(this.value)">
                </div>
                <div class="u-maxh-200 u-overflow-y-auto u-bd-1-solid-border-color u-r-8 u-p-4 u-col u-gap-2" id="new-group-contacts-list" style="display: flex;">
                    <!-- Contacts are listed dynamically -->
                </div>
            </div>
        </div>
        <div class="u-p-12-20 u-border-top-1-solid-border-color u-bg-bg-main u-d-flex u-jc-flex-end u-gap-8">
            <button type="button" class="btn btn-sm btn-secondary" onclick="closeNewGroupModal()"><?php echo t('common.cancel'); ?></button>
            <button type="button" id="new-group-submit-btn" class="btn btn-sm btn-primary" onclick="submitCreateGroup()">
                <i class="fas fa-check"></i> <?php echo t('chat.create_group'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Group info modal -->
<div class="u-pos-fixed u-inset-0 u-bg-rgba-0-0-0-0-6 u-z-index-9999 u-ai-center u-jc-center u-p-20" id="chat-group-info-modal" style="display: none;" onclick="closeGroupInfoModal()">
    <div class="card u-w-100 u-maxw-500 u-maxh-90vh u-d-flex u-col u-bg-bg-card u-r-12 u-box-shadow-0-10-40-rgba-0-0-0-0-25 u-of-hidden u-p-0" onclick="event.stopPropagation()">
        <div class="u-p-16-20 u-border-bottom-1-solid-border-color u-d-flex u-ai-center u-jc-space-between">
            <h4 class="u-m-0 u-fs-16 u-fw-700 u-text-main">
                <?php echo t('chat.group_info'); ?>
            </h4>
            <button type="button" class="btn btn-icon btn-sm u-bd-none u-bg-transparent u-muted u-pointer" onclick="closeGroupInfoModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="u-p-20 u-overflow-y-auto u-d-flex u-col u-gap-16">
            <!-- Group header card -->
            <div class="u-d-flex u-ai-center u-gap-16 u-p-12 u-bg-bg-main u-r-12">
                <div class="u-w-54 u-h-54 u-r-50pc u-bg-linear-gradient-135deg-6366f1-8b5cf6 u-c-fff u-ai-center u-jc-center u-fs-24 u-flex-shrink-0 u-of-hidden" id="group-info-avatar-box" style="display: flex;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="u-flex-1 u-minw-0">
                    <div class="u-flex-center">
                        <h4 class="u-m-0 u-fs-16 u-fw-700 u-text-main u-word-break-break-word" id="group-info-title"><?php echo t('chat.group'); ?></h4>
                        <button id="group-info-edit-btn" class="btn btn-icon btn-sm btn-outline-secondary u-r-8" style="display: none;" onclick="promptEditGroupInfo()" title="<?php echo t('chat.edit_group'); ?>">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                    </div>
                    <p class="u-m-4-0-0-0 u-fs-12 u-muted u-word-break-break-word" id="group-info-desc"></p>
                    <div id="group-info-meta" class="u-muted u-fs-11 u-mt-4"></div>
                </div>
            </div>

            <!-- Participants header & add-member button -->
            <div>
                <div class="u-d-flex u-jc-space-between u-ai-center u-mb-8">
                    <span class="u-fs-13 u-fw-700 u-text-main">
                        <?php echo t('chat.participants'); ?> (<span id="group-info-members-count">0</span>)
                    </span>
                    <button id="group-info-add-member-btn" class="btn btn-sm btn-outline-primary u-r-8" style="display: none;" onclick="openAddMembersModal()">
                        <i class="fas fa-user-plus"></i> <?php echo t('chat.add_member'); ?>
                    </button>
                </div>
                <div class="u-col u-gap-4 u-maxh-220 u-overflow-y-auto u-pr-2" id="group-info-members-list" style="display: flex;">
                    <!-- The member list is rendered dynamically -->
                </div>
            </div>
        </div>
        <div class="u-p-12-20 u-border-top-1-solid-border-color u-bg-bg-main u-d-flex u-jc-space-between u-ai-center">
            <div>
                <button id="group-info-delete-btn" type="button" class="btn btn-sm btn-outline-danger" style="display: none" onclick="confirmDeleteGroup()">
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
<div class="u-pos-fixed u-inset-0 u-bg-rgba-0-0-0-0-6 u-z-index-10000 u-ai-center u-jc-center u-p-20" id="chat-add-members-modal" style="display: none;" onclick="closeAddMembersModal()">
    <div class="card u-w-100 u-maxw-420 u-maxh-80vh u-d-flex u-col u-bg-bg-card u-r-12 u-box-shadow-0-10-40-rgba-0-0-0-0-25 u-of-hidden u-p-0" onclick="event.stopPropagation()">
        <div class="u-p-16-20 u-border-bottom-1-solid-border-color u-d-flex u-ai-center u-jc-space-between">
            <h4 class="u-m-0 u-fs-16 u-fw-700 u-text-main">
                <?php echo t('chat.add_members_title'); ?>
            </h4>
            <button type="button" class="btn btn-icon btn-sm u-bd-none u-bg-transparent u-muted u-pointer" onclick="closeAddMembersModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="u-p-16 u-overflow-y-auto u-d-flex u-col u-gap-12">
            <div class="u-relative">
                <i class="fas fa-search u-pos-absolute u-left-10 u-top-9 u-muted u-fs-12"></i>
                <input class="u-w-100 u-p-8-12-8-32 u-r-8 u-bd-1-solid-border-color u-bg-bg-main u-text-main u-fs-13 u-outline-none" type="text" id="add-members-search" placeholder="<?php echo t('chat.filter_contacts_ph'); ?>" oninput="filterAddMembersContacts(this.value)">
            </div>
            <div class="u-maxh-240 u-overflow-y-auto u-bd-1-solid-border-color u-r-8 u-p-4 u-col u-gap-2" id="add-members-contacts-list" style="display: flex;">
                <!-- Contacts that can be added -->
            </div>
        </div>
        <div class="u-p-12-20 u-border-top-1-solid-border-color u-bg-bg-main u-d-flex u-jc-flex-end u-gap-8">
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
// Minutes after sending a message can be deleted (0 = always); the chat service enforces it.
window.CHAT_DELETE_WINDOW = <?= $delete_window ?>;
</script>
<script src="<?php echo asset('/assets/js/chat.js'); ?>"></script>
