<div class="card fax-send-card">
    <?php echo uiPageHeaderOpen('fa-paper-plane', t('fax_send.header_title'), 'faxSendHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('faxSendHelpBox', t('fax_send.help_title')); ?>
        <?php echo t('fax_send.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>



    <!-- Send mode tabs: Upload PDF / Write text -->
    <div class="fax-compose-tabs u-m-12-20-0">
        <button type="button" class="fax-compose-tab-btn active" data-mode="pdf" onclick="switchFaxComposeMode('pdf')">
            <i class="fas fa-file-pdf"></i> <?php echo t('fax_send.tab_pdf'); ?>
        </button>
        <button type="button" class="fax-compose-tab-btn" data-mode="text" onclick="switchFaxComposeMode('text')">
            <i class="fas fa-align-left"></i> <?php echo t('fax_send.tab_text'); ?>
        </button>
    </div>

    <?php
    // Who has no fax number to send from (issue #154) is told so, instead of a number the server refuses.
    $canSend = $user_role === 'admin' ? ($fax_users !== [] || $user_ext !== '') : $own_numbers !== [];
    ?>
    <?php if (!$canSend): ?>
        <div class="alert alert-warning u-m-16-20"><i class="fas fa-triangle-exclamation"></i> <?php echo t('fax_send.no_number'); ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="off" enctype="multipart/form-data" id="faxSendForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="compose_mode" id="fax_compose_mode_input" value="pdf">
        <input type="hidden" name="fax_text_content" id="fax_text_content_input">

        <div class="fax-send-fields">
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
            <?php elseif (count($own_numbers) > 1): ?>
                <select name="sender_did" class="form-control" required>
                    <?php foreach ($own_numbers as $number): ?>
                        <option value="<?php echo htmlspecialchars($number); ?>"<?php echo $number === (string) $user_ext ? ' selected' : ''; ?>><?php echo htmlspecialchars($number); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" name="sender_did" class="form-control u-opacity-0-8" value="<?php echo htmlspecialchars($user_role === 'admin' ? $user_ext : ($own_numbers[0] ?? '')); ?>" readonly>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo t('fax_send.field_dest'); ?></label>
            <div class="u-relative">
                <i class="fas fa-phone-alt u-pos-absolute u-left-16 u-top-15 u-muted"></i>
                <input type="text" name="dest_number" class="form-control u-pl-44" required>
            </div>
            <small class="u-hint u-fs-11"><?php echo t('fax_send.dest_help'); ?></small>
        </div>

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

        <?php if ($cover['mode'] !== 'off'): ?>
        <div class="form-group u-bd-1-solid-border-color u-r-8 u-p-16">
            <label class="u-d-flex u-ai-center u-gap-8 u-fw-600 u-fs-13">
                <input type="checkbox" name="cover_enabled" value="1" checked <?php echo $cover['mode'] === 'required' ? 'disabled' : ''; ?>>
                <?php echo t($cover['mode'] === 'required' ? 'fax_cover.field_required' : 'fax_cover.field_enabled'); ?>
            </label>
            <?php if (!empty($templates)): ?>
            <div class="form-group u-mt-12 u-mb-0">
                <label class="form-label"><?php echo t('fax_cover.field_template'); ?></label>
                <select name="cover_template" class="form-control">
                    <option value="0"><?php echo t('fax_cover.template_org'); ?></option>
                    <?php foreach ($templates as $tpl): ?><option value="<?php echo (int) $tpl['id']; ?>"><?php echo htmlspecialchars((string) $tpl['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="u-grid-2 u-mt-12">
                <div class="form-group u-mb-0">
                    <label class="form-label"><?php echo t('fax_cover.label_to'); ?></label>
                    <input type="text" name="cover_to" class="form-control" maxlength="120">
                </div>
                <div class="form-group u-mb-0">
                    <label class="form-label"><?php echo t('fax_cover.label_subject'); ?></label>
                    <input type="text" name="cover_subject" class="form-control" maxlength="200">
                </div>
            </div>
            <div class="form-group u-mt-12">
                <label class="form-label"><?php echo t('fax_cover.field_message'); ?></label>
                <textarea name="cover_message" class="form-control" rows="3" maxlength="2000"></textarea>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" name="cover_preview" value="1" formaction="/fax-send" formtarget="_blank" formnovalidate data-no-spa="true"><i class="fas fa-eye"></i> <?php echo t('fax_cover.preview'); ?></button>
        </div>
        <?php endif; ?>

        <?php if (hasModulePermission('fax_send', 'edit')): ?>
        <div class="form-actions"><button type="submit" class="btn btn-primary" title="<?php echo t('fax_send.send_tooltip'); ?>"<?php echo $canSend ? '' : ' disabled'; ?>>
            <i class="fas fa-paper-plane"></i> <span><?php echo t('fax_send.send_tooltip'); ?></span>
        </button></div>
        <?php else: ?>
        <div class="u-mt-16 u-p-12 u-r-8 u-bg-bg-card u-bd-1-solid-border-color u-ta-center u-muted u-fs-13">
            <i class="fas fa-info-circle"></i> <?php echo t('common.readonly_mode'); ?>
        </div>
        <?php endif; ?>
    </form>
</div>

<link rel="stylesheet" href="<?php echo asset('/assets/css/quill.snow.css'); ?>">
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/fax_send.css'); ?>">
<script src="<?php echo asset('/assets/js/quill.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/fax_send.js'); ?>"></script>

<div class="card" id="fax-templates">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-file-alt u-primary"></i> <?php echo t('fax_cover.my_templates'); ?></div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="faxEditTemplate({})"><i class="fas fa-plus"></i> <?php echo t('fax_cover.template_add'); ?></button>
    </div>
    <p class="u-muted u-fs-12 u-card-text"><?php echo t($cover['mode'] === 'off' ? 'fax_cover.templates_help_off' : 'fax_cover.templates_help'); ?></p>
    <?php if (!empty($templates)): ?>
    <?php echo uiTableOpen([]); ?>
        <?php foreach ($templates as $tpl): ?>
            <tr>
                <td class="u-strong"><?php echo htmlspecialchars((string) $tpl['name']); ?></td>
                <td class="text-muted u-fs-12"><?php echo htmlspecialchars((string) $tpl['company']); ?></td>
                <td class="text-right u-nowrap">
                    <button type="button" class="btn btn-icon btn-xs btn-secondary" onclick='faxEditTemplate(<?php echo htmlspecialchars(json_encode($tpl), ENT_QUOTES); ?>)'><i class="fas fa-edit"></i></button>
                    <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('fax_cover.template_delete_confirm')), ENT_QUOTES); ?>);">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="delete_cover_template" value="1">
                        <input type="hidden" name="template_id" value="<?php echo (int) $tpl['id']; ?>">
                        <button type="submit" class="btn btn-icon btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="faxTemplateModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-file-alt u-primary"></i> <?php echo t('fax_cover.my_templates'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('faxTemplateModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="save_cover_template" value="1">
                <input type="hidden" name="template_id" id="template_id" value="0">
                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_cover.template_name'); ?></label>
                    <input type="text" name="template_name" id="template_name" class="form-control" maxlength="60" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_cover.template_company'); ?></label>
                    <input type="text" name="template_company" id="template_company" class="form-control" maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_cover.template_notice'); ?></label>
                    <textarea name="template_notice" id="template_notice" class="form-control" rows="3" maxlength="1000"></textarea>
                </div>
                <label class="u-check-label u-mb-12"><input type="checkbox" name="template_logo" id="template_logo" value="1" class="u-accent"> <?php echo t('fax_cover.template_logo'); ?></label>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('faxTemplateModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>
<script>
function faxEditTemplate(t) {
    document.getElementById('template_id').value = t.id || 0;
    document.getElementById('template_name').value = t.name || '';
    document.getElementById('template_company').value = t.company || '';
    document.getElementById('template_notice').value = t.notice || '';
    document.getElementById('template_logo').checked = parseInt(t.logo || 0, 10) === 1;
    UIHelper.openOverlayModal('faxTemplateModal');
}
</script>
