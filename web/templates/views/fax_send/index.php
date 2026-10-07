<div class="card" style="max-width: 760px; margin: 0 auto;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-paper-plane u-primary"></i> <?php echo t('fax_send.header_title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('faxSendHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="faxSendHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fax_send.help_title'); ?></h4>
        <?php echo t('fax_send.help_body'); ?>
    </div>



    <!-- Send mode tabs: Upload PDF / Write text -->
    <div class="fax-compose-tabs" style="display: flex; gap: 4px; padding: 0 20px; margin-top: 12px; border-bottom: 1px solid var(--border-color);">
        <button type="button" class="fax-compose-tab-btn active" data-mode="pdf" onclick="switchFaxComposeMode('pdf')">
            <i class="fas fa-file-pdf"></i> <?php echo t('fax_send.tab_pdf'); ?>
        </button>
        <button type="button" class="fax-compose-tab-btn" data-mode="text" onclick="switchFaxComposeMode('text')">
            <i class="fas fa-align-left"></i> <?php echo t('fax_send.tab_text'); ?>
        </button>
    </div>

    <form method="POST" autocomplete="off" enctype="multipart/form-data" id="faxSendForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="compose_mode" id="fax_compose_mode_input" value="pdf">
        <input type="hidden" name="fax_text_content" id="fax_text_content_input">

        <div class="form-group">
            <label class="form-label"><?php echo t('fax_send.field_sender'); ?></label>
            <?php if ($user_role === 'admin' && !empty($fax_users)): ?>
                <select name="sender_did" class="form-control" required>
                    <option value=""><?php echo t('fax_send.sender_select_placeholder'); ?></option>
                    <?php foreach ($fax_users as $fu): ?>
                        <option value="<?php echo htmlspecialchars($fu['extension']); ?>"><?php echo htmlspecialchars($fu['full_name']); ?> (<?php echo htmlspecialchars($fu['extension']); ?>)</option>
                    <?php endforeach; ?>
                </select>
                <small class="u-hint u-fs-11"><?php echo t('fax_send.sender_admin_help'); ?></small>
            <?php else: ?>
                <input type="text" name="sender_did" class="form-control" value="<?php echo htmlspecialchars($user_ext); ?>" readonly style="opacity: 0.8;">
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo t('fax_send.field_dest'); ?></label>
            <div class="u-relative">
                <i class="fas fa-phone-alt" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                <input type="text" name="dest_number" class="form-control" style="padding-left: 44px;" required>
            </div>
            <small class="u-hint u-fs-11"><?php echo t('fax_send.dest_help'); ?></small>
        </div>

        <div id="fax-compose-pdf" class="fax-compose-panel">
            <div class="form-group">
                <label class="form-label"><?php echo t('fax_send.field_pdf'); ?></label>
                <input type="file" name="pdf_file" id="fax_pdf_file_input" class="form-control" accept=".pdf">
                <small class="u-hint u-fs-11"><?php echo t('fax_send.pdf_help'); ?></small>
            </div>
        </div>

        <div id="fax-compose-text" class="fax-compose-panel" style="display: none;">
            <div class="form-group">
                <label class="form-label"><?php echo t('fax_send.field_text'); ?></label>
                <div id="fax-text-toolbar">
                    <select class="ql-font">
                        <option value="dejavusans" selected>Sans</option>
                        <option value="dejavuserif">Serif</option>
                        <option value="dejavusansmono"><?php echo t('fax_send.font_mono'); ?></option>
                    </select>
                    <select class="ql-size">
                        <option value="10px">10</option>
                        <option value="12px" selected>12</option>
                        <option value="14px">14</option>
                        <option value="16px">16</option>
                        <option value="18px">18</option>
                        <option value="20px">20</option>
                        <option value="24px">24</option>
                    </select>
                    <button class="ql-bold"></button>
                    <button class="ql-italic"></button>
                    <button class="ql-underline"></button>
                    <select class="ql-align"></select>
                    <button class="ql-list" value="ordered"></button>
                    <button class="ql-list" value="bullet"></button>
                </div>
                <div id="fax-text-editor" data-placeholder="<?php echo htmlspecialchars(t('fax_send.text_placeholder')); ?>"></div>
                <small class="u-hint u-fs-11"><?php echo t('fax_send.text_help'); ?></small>
            </div>
        </div>

        <?php if (hasModulePermission('fax_send', 'edit')): ?>
        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 10px; font-size: 15px;" title="<?php echo t('fax_send.send_tooltip'); ?>">
            <i class="fas fa-paper-plane"></i>
        </button>
        <?php else: ?>
        <div style="margin-top: 15px; padding: 12px; border-radius: 8px; background: var(--bg-card); border: 1px solid var(--border-color); text-align: center; color: var(--text-muted); font-size: 13px;">
            <i class="fas fa-info-circle"></i> <?php echo t('common.readonly_mode', 'Sadece izleyici modundasınız; faks gönderimi yapamazsınız.'); ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<link rel="stylesheet" href="<?php echo asset('/assets/css/quill.snow.css'); ?>">
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/fax_send.css'); ?>">
<script src="<?php echo asset('/assets/js/quill.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/fax_send.js'); ?>"></script>
