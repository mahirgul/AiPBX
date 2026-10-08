<?php
/** @var string $templateKey @var string $lang @var array $template @var array $customized @var array $variables @var string $systemLanguage @var string $myEmail */
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="settings-tabs">
    <a class="settings-tab-btn" href="/mail-settings"><i class="fas fa-server"></i> <?php echo t('mail_settings.tab_smtp'); ?></a>
    <a class="settings-tab-btn" href="/mail-settings#sender"><i class="fas fa-at"></i> <?php echo t('mail_settings.tab_sender'); ?></a>
    <a class="settings-tab-btn" href="/mail-settings#test"><i class="fas fa-paper-plane"></i> <?php echo t('mail_settings.tab_test'); ?></a>
    <a class="settings-tab-btn active" href="/mail-templates"><i class="fas fa-envelope-open-text"></i> <?php echo t('mail_templates.tab'); ?></a>
</div>

<div class="card">
    <p class="u-hint" style="margin: 0 0 12px 0;"><?php echo t('mail_templates.intro'); ?></p>
    <form method="POST" class="u-flex-gap" style="flex-wrap: wrap; align-items: center;">
        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
        <input type="hidden" name="save_default_language" value="1">
        <label class="form-label" style="margin: 0;"><i class="fas fa-globe"></i> <?php echo t('mail_templates.default_language'); ?></label>
        <select name="mail_default_language" class="form-control" style="max-width: 220px;">
            <?php foreach (UI_LANGUAGES as $code => $label): ?>
                <option value="<?php echo $e($code); ?>"<?php echo $code === $systemLanguage ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
        <small class="u-hint" style="flex-basis: 100%;"><?php echo t('mail_templates.default_language_help'); ?></small>
    </form>
</div>

<div class="mail-tpl-layout">
    <div class="card mail-tpl-list">
        <?php foreach (array_keys(MailTemplateService::TEMPLATES) as $k): $langs = $customized[$k] ?? []; ?>
            <a href="/mail-templates?t=<?php echo $e($k); ?>&amp;lang=<?php echo $e($lang); ?>" class="mail-tpl-item<?php echo $k === $templateKey ? ' active' : ''; ?>">
                <span><?php echo t('mail_templates.name.' . $k); ?></span>
                <?php if ($langs): ?>
                    <span class="badge badge-info u-fs-11" title="<?php echo $e(t('mail_templates.custom')); ?>"><?php echo $e(strtoupper(implode(' ', $langs))); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="card mail-tpl-editor" id="mailTplEditor"
         data-template="<?php echo $e($templateKey); ?>" data-csrf="<?php echo $e(getCSRFToken()); ?>">
        <div class="u-flex-between u-mb-10" style="flex-wrap: wrap; gap: 8px;">
            <div class="card-title" style="margin: 0;">
                <i class="fas fa-envelope u-primary"></i> <?php echo t('mail_templates.name.' . $templateKey); ?>
                <span class="badge <?php echo $template['custom'] ? 'badge-info' : 'badge-secondary'; ?> u-fs-11" id="mailTplState"
                      data-custom="<?php echo $e(t('mail_templates.custom')); ?>" data-builtin="<?php echo $e(t('mail_templates.builtin')); ?>">
                    <?php echo $template['custom'] ? t('mail_templates.custom') : t('mail_templates.builtin'); ?>
                </span>
            </div>
            <label class="u-flex-center u-fs-12" style="gap: 6px; margin: 0;">
                <?php echo t('mail_templates.language'); ?>
                <select id="mailTplLang" class="form-control form-control-sm" style="width: auto;">
                    <?php foreach (UI_LANGUAGES as $code => $label): ?>
                        <option value="<?php echo $e($code); ?>"<?php echo $code === $lang ? ' selected' : ''; ?>><?php echo $e($label); ?><?php echo in_array($code, $customized[$templateKey] ?? [], true) ? ' ✎' : ''; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo t('mail_templates.subject'); ?></label>
            <input type="text" id="mailTplSubject" class="form-control" maxlength="255" value="<?php echo $e($template['subject']); ?>">
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo t('mail_templates.body'); ?></label>
            <div class="mail-tpl-toolbar">
                <button type="button" class="btn btn-secondary btn-sm" data-wrap="strong" title="<?php echo $e(t('mail_templates.tb_bold')); ?>"><i class="fas fa-bold"></i></button>
                <button type="button" class="btn btn-secondary btn-sm" data-wrap="em" title="<?php echo $e(t('mail_templates.tb_italic')); ?>"><i class="fas fa-italic"></i></button>
                <button type="button" class="btn btn-secondary btn-sm" data-wrap="p" title="<?php echo $e(t('mail_templates.tb_paragraph')); ?>"><i class="fas fa-paragraph"></i></button>
                <button type="button" class="btn btn-secondary btn-sm" data-list="1" title="<?php echo $e(t('mail_templates.tb_list')); ?>"><i class="fas fa-list-ul"></i></button>
                <button type="button" class="btn btn-secondary btn-sm" data-link="1" title="<?php echo $e(t('mail_templates.tb_link')); ?>"
                        data-prompt="<?php echo $e(t('mail_templates.link_prompt')); ?>"><i class="fas fa-link"></i></button>
            </div>
            <textarea id="mailTplBody" class="form-control mail-tpl-body" rows="14" spellcheck="false"><?php echo $e($template['body']); ?></textarea>
            <small class="u-hint"><?php echo t('mail_templates.html_note'); ?></small>
        </div>

        <div class="form-group">
            <label class="form-label"><?php echo t('mail_templates.variables'); ?></label>
            <div class="mail-tpl-vars">
                <?php foreach ($variables as $v): $block = str_starts_with($v, '*'); $name = ltrim($v, '*'); ?>
                    <button type="button" class="mail-tpl-var<?php echo $block ? ' block' : ''; ?>" data-var="<?php echo $e($name); ?>">{<?php echo $e($name); ?>}</button>
                <?php endforeach; ?>
            </div>
            <small class="u-hint"><?php echo t('mail_templates.variables_help'); ?></small>
        </div>

        <div class="u-flex-gap" style="flex-wrap: wrap;">
            <button type="button" class="btn btn-primary" id="mailTplSave"><i class="fas fa-save"></i> <?php echo t('mail_templates.save'); ?></button>
            <button type="button" class="btn btn-secondary" id="mailTplReset" data-confirm="<?php echo $e(t('mail_templates.reset_confirm')); ?>"><i class="fas fa-undo"></i> <?php echo t('mail_templates.reset'); ?></button>
            <span style="flex: 1;"></span>
            <input type="email" id="mailTplTestTo" class="form-control" style="max-width: 240px;" placeholder="<?php echo $e(t('mail_templates.test_to')); ?>" value="<?php echo $e($myEmail); ?>">
            <button type="button" class="btn btn-secondary" id="mailTplTest"><i class="fas fa-paper-plane"></i> <?php echo t('mail_templates.send_test'); ?></button>
        </div>
        <small class="u-hint" id="mailTplUnsaved" style="display: none; color: var(--warning);"><?php echo t('mail_templates.unsaved'); ?></small>
    </div>

    <div class="card mail-tpl-preview-card">
        <div class="card-title"><i class="fas fa-eye u-primary"></i> <?php echo t('mail_templates.preview'); ?></div>
        <div class="mail-tpl-preview-subject" id="mailTplPreviewSubject"></div>
        <iframe id="mailTplPreview" class="mail-tpl-preview" sandbox="" title="<?php echo $e(t('mail_templates.preview')); ?>"></iframe>
    </div>
</div>

<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/mail_templates.css'); ?>">
<script src="<?php echo asset('/assets/js/mail_templates.js'); ?>"></script>
