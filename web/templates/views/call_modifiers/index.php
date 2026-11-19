<?php
/** @var array $modifiers @var array $dest_modules @var array $languages */
use PBX\Destinations\DestinationRegistry;
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$destCache = [];
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-tags', t('call_modifiers.title'), 'callModifiersHelpBox'); ?>
    <?php echo uiPageHeaderActions(); ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openCreateModifierModal()">
            <i class="fas fa-plus-circle"></i> <?php echo $h(t('common.add_new')); ?>
        </button>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('callModifiersHelpBox', t('call_modifiers.help_title')); ?>
        <?php echo $h(t('call_modifiers.help_body')); ?>
    <?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        t('common.title'),
        t('call_modifiers.col_action'),
        t('call_modifiers.col_target'),
        t('common.status'),
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ]); ?>
        <?php if (empty($modifiers)): ?>
            <?php echo uiTableEmptyRow(5, t('call_modifiers.empty'), 'fa-tags'); ?>
        <?php else: ?>
            <?php foreach ($modifiers as $m):
                $dMod = DestinationRegistry::getModule($m['dest_type']);
                $dName = $dMod ? $dMod->getName() : $m['dest_type'];
                $dLabel = DestinationRegistry::resolveLabel($m['dest_type'], $m['dest_id'], $destCache);
                $badge = $m['mod_type'] === 'language'
                    ? '<span class="badge badge-info"><i class="fas fa-language"></i> ' . $h(t('call_modifiers.type_language')) . ': ' . $h($m['mod_value']) . '</span>'
                    : '<span class="badge badge-purple"><i class="fas fa-tag"></i> ' . $h(t('call_modifiers.type_cid_prefix')) . ': ' . $h($m['mod_value']) . '</span>';
            ?>
                <tr>
                    <td class="u-strong"><?php echo $h($m['title']); ?></td>
                    <td><?php echo $badge; ?></td>
                    <td>
                        <span class="badge <?php echo $h(DestinationRegistry::badgeClassFor($m['dest_type'])); ?>">
                            <?php echo $h($dName); ?><?php echo $dLabel !== null ? ': ' . $h($dLabel) : ''; ?>
                        </span>
                    </td>
                    <td><?php echo uiStatusToggleForm($m['id'], $m['is_active'], 'modifier_id'); ?></td>
                    <td class="text-right">
                        <?php echo uiRowActions($m, 'openEditModifierModal', 'modifier_id', 'delete_modifier', t('call_modifiers.confirm_delete')); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php echo uiTableClose(); ?>
</div>

<div class="modal-overlay" id="modifierModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title" id="modifierModalTitle"><i class="fas fa-tags u-primary"></i> <?php echo $h(t('call_modifiers.new_title')); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="closeModifierModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $h(getCSRFToken()); ?>">
                <input type="hidden" name="save_modifier" value="1">
                <input type="hidden" name="modifier_id" id="mod_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo $h(t('common.title')); ?></label>
                    <input type="text" name="title" id="mod_title" class="form-control" required maxlength="120" placeholder="<?php echo $h(t('call_modifiers.title_ph')); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo $h(t('call_modifiers.field_type')); ?></label>
                    <select name="mod_type" id="mod_type" class="form-control" onchange="toggleModifierTypeField()">
                        <option value="language"><?php echo $h(t('call_modifiers.type_language')); ?></option>
                        <option value="cid_prefix"><?php echo $h(t('call_modifiers.type_cid_prefix')); ?></option>
                    </select>
                </div>

                <div class="form-group" id="mod_lang_group">
                    <label class="form-label"><?php echo $h(t('call_modifiers.field_language')); ?></label>
                    <select id="mod_lang_select" class="form-control" onchange="syncModifierValue()">
                        <?php foreach ($languages as $langCode): ?>
                            <option value="<?php echo $h($langCode); ?>"><?php echo $h(strtoupper($langCode)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="mod_prefix_group" style="display:none;">
                    <label class="form-label"><?php echo $h(t('call_modifiers.field_prefix')); ?></label>
                    <input type="text" id="mod_prefix_input" class="form-control" maxlength="40" placeholder="VIP " oninput="syncModifierValue()">
                    <small class="u-hint"><?php echo $h(t('call_modifiers.prefix_help')); ?></small>
                </div>

                <input type="hidden" name="mod_value" id="mod_value" value="<?php echo $h($languages[0] ?? 'en'); ?>">

                <div class="u-d-grid u-gtc-1fr-1fr u-gap-12">
                    <div class="form-group">
                        <label class="form-label"><?php echo $h(t('call_modifiers.field_dest_type')); ?></label>
                        <select name="dest_type" id="mod_dest_type" class="form-control">
                            <?php foreach ($dest_modules as $dm): ?>
                                <option value="<?php echo $h($dm->getKey()); ?>"><?php echo $h($dm->getName()); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo $h(t('call_modifiers.field_dest_target')); ?></label>
                        <select name="dest_id" id="mod_dest_id" class="form-control"></select>
                    </div>
                </div>

                <div class="form-group u-mt-8">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="mod_is_active" value="1" checked class="u-check">
                        <span><?php echo $h(t('common.active')); ?></span>
                    </label>
                </div>

                <div class="u-d-flex u-jc-flex-end u-mt-16 u-gap-8">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeModifierModal()"><?php echo $h(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary btn-sm"><?php echo $h(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="<?php echo asset('/assets/js/destinations_helper.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/call_modifiers.js'); ?>"></script>
