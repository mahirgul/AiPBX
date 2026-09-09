<div class="card" style="max-width: 760px; margin: 0 auto;">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-paper-plane" style="color: var(--primary);"></i> <?php echo t('fax_send.header_title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('faxSendHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="faxSendHelpBox" style="margin: 16px 20px 0 20px;">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fax_send.help_title'); ?></h4>
        <?php echo t('fax_send.help_body'); ?>
    </div>



    <!-- Gönderim Modu Sekmeleri: PDF Yükle / Metin Yaz -->
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
                <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('fax_send.sender_admin_help'); ?></small>
            <?php else: ?>
                <input type="text" name="sender_did" class="form-control" value="<?php echo htmlspecialchars($user_ext); ?>" readonly style="opacity: 0.8;">
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo t('fax_send.field_dest'); ?></label>
            <div style="position: relative;">
                <i class="fas fa-phone-alt" style="position: absolute; left: 16px; top: 15px; color: var(--text-muted);"></i>
                <input type="text" name="dest_number" class="form-control" style="padding-left: 44px;" required>
            </div>
            <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('fax_send.dest_help'); ?></small>
        </div>

        <div id="fax-compose-pdf" class="fax-compose-panel">
            <div class="form-group">
                <label class="form-label"><?php echo t('fax_send.field_pdf'); ?></label>
                <input type="file" name="pdf_file" id="fax_pdf_file_input" class="form-control" accept=".pdf">
                <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('fax_send.pdf_help'); ?></small>
            </div>
        </div>

        <div id="fax-compose-text" class="fax-compose-panel" style="display: none;">
            <div class="form-group">
                <label class="form-label"><?php echo t('fax_send.field_text'); ?></label>
                <div id="fax-text-toolbar">
                    <select class="ql-font">
                        <option value="dejavusans" selected>Sans</option>
                        <option value="dejavuserif">Serif</option>
                        <option value="dejavusansmono">Daktilo</option>
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
                <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('fax_send.text_help'); ?></small>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; margin-top: 10px; font-size: 15px;" title="<?php echo t('fax_send.send_tooltip'); ?>">
            <i class="fas fa-paper-plane"></i>
        </button>
    </form>
</div>

<link rel="stylesheet" href="/assets/css/quill.snow.css?v=<?php echo time(); ?>">
<style>
    .fax-compose-tab-btn {
        background: none; border: none; border-bottom: 3px solid transparent;
        padding: 10px 16px; font-size: 13px; font-weight: 700; color: var(--text-muted);
        cursor: pointer; display: flex; align-items: center; gap: 6px;
    }
    .fax-compose-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
    #fax-text-editor { background: #fff; color: #1a1a1a; min-height: 260px; }
    #fax-text-editor .ql-editor { min-height: 260px; font-size: 12px; }
    /* Quill'in ql-size sinif tabanli boyutlandirmasi devre disi - style tabanli
       attributor kullaniliyor (assets/js/fax_send.js), boylece uretilen HTML
       kendi icinde tasinabilir (inline style), Quill'in kendi CSS'ine bagimli degil. */

    /* Quill'in varsayilan font seciciyi 108px'e sabitleyen kurali dar kaliyordu -
       Turkce etiketler ("Sans"/"Serif"/"Daktilo" olarak zaten kisaltildi) yine de
       tasip alt satira sarkiyordu (2026-08-31, kullanici bulgusu). Araç çubuğu
       geneli de sarilabilir/mobilde daha sik hale getirildi. */
    #fax-text-toolbar.ql-toolbar.ql-snow {
        display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px;
        padding: 8px 10px;
    }
    #fax-text-toolbar .ql-formats { margin-right: 0; display: flex; align-items: center; gap: 2px; }
    #fax-text-toolbar .ql-picker.ql-font { width: 92px; }
    #fax-text-toolbar .ql-picker.ql-size { width: 60px; }
    #fax-text-toolbar .ql-picker-label { padding-left: 6px; }

    @media (max-width: 600px) {
        #fax-text-toolbar.ql-toolbar.ql-snow { gap: 4px 6px; padding: 6px 8px; }
        #fax-text-toolbar .ql-picker.ql-font { width: 78px; }
        #fax-text-toolbar .ql-picker.ql-size { width: 52px; }
        #fax-text-toolbar .ql-picker { font-size: 12px; }
        #fax-text-toolbar button { width: 22px; }
    }
</style>
<script src="/assets/js/quill.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/fax_send.js?v=<?php echo time(); ?>"></script>
