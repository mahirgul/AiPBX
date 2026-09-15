<?php $destResolveCache = []; ?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-microphone-alt" style="color: var(--primary);"></i> <?php echo t('ivr.title'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('ivrHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('ivrs', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateIvrModal()" title="Yeni Ekle">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="ivrHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('ivr.help_title'); ?></h4>
        <?php echo t('ivr.help_body'); ?><br>
        - <strong><?php echo t('ivr.help_digits'); ?></strong><br>
        - <strong><?php echo t('ivr.help_timeout'); ?></strong>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 45px;">#</th>
                    <th><?php echo t('ivr.col_ivr'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('internal_number.col'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('ivr.col_audio'); ?></th>
                    <th><?php echo t('ivr.col_timeout'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('ivr.col_digits'); ?></th>
                    <th><?php echo t('ivr.col_status'); ?></th>
                    <th style="text-align: right;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ivrs as $ivr):
                    $entries = $entries_by_ivr[$ivr['id']] ?? [];
                ?>
                    <tr>
                        <td class="col-hide-mobile" style="color: var(--text-muted); font-size: 12px;">#<?php echo $ivr['id']; ?></td>
                        <td style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($ivr['title']); ?></td>
                        <td class="col-hide-mobile">
                            <?php echo !empty($ivr['internal_number'])
                                ? '<span class="badge badge-info">' . htmlspecialchars($ivr['internal_number']) . '</span>'
                                : t('internal_number.none'); ?>
                        </td>
                        <td class="col-hide-mobile">
                            <span class="badge badge-info"><i class="fas fa-volume-up"></i> <?php echo htmlspecialchars($ivr['prompt_file']); ?></span>
                        </td>
                        <td>
                            <div style="font-size: 11px; display: flex; flex-direction: column; gap: 4px;">
                                <span><i class="fas fa-clock" style="color: var(--primary);"></i> <strong><?php echo sprintf(t('ivr.timeout_label'), intval($ivr['timeout_seconds'])); ?></strong> <?php echo IvrRepository::destBadge($ivr['timeout_dest_type'], $ivr['timeout_dest_id'], $destResolveCache); ?></span>
                                <span><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> <strong><?php echo t('ivr.invalid_label'); ?></strong> <?php echo IvrRepository::destBadge($ivr['invalid_dest_type'] ?? 'hangup', $ivr['invalid_dest_id'] ?? 'hangup', $destResolveCache); ?></span>
                            </div>
                        </td>
                        <td class="col-hide-mobile">
                            <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                                <?php foreach ($entries as $en): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-color); border-radius: 4px; padding: 2px 6px;">
                                        <strong><?php echo t('ivr.key_label'); ?> <?php echo htmlspecialchars($en['digit']); ?>:</strong> <?php echo IvrRepository::destBadge($en['dest_type'], $en['dest_id'], $destResolveCache); ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (empty($entries)): ?>
                                    <span style="color: var(--text-muted); font-size: 12px;"><?php echo t('ivr.no_entries'); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php echo uiStatusToggleForm($ivr['id'], $ivr['is_active'], 'ivr_id'); ?>
                        </td>
                        <td style="text-align: right; display: flex; gap: 6px; justify-content: flex-end;">
                            <button class="btn btn-secondary btn-sm" onclick='openIvrEntriesModal(<?php echo $ivr['id']; ?>, "<?php echo htmlspecialchars($ivr['title'], ENT_QUOTES); ?>")' title="<?php echo htmlspecialchars(t('ivr.entries_map_tooltip')); ?>">
                                <i class="fas fa-th"></i>
                            </button>
                            <?php echo uiEditButton($ivr, 'openEditIvrModal'); ?>
                            <?php echo uiDeleteForm($ivr['id'], 'ivr_id', 'delete_ivr', t('ivr.confirm_delete')); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit IVR Modal -->
<div class="modal-overlay" id="ivrModal">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="ivrModalTitle"><i class="fas fa-microphone-alt" style="color: var(--primary);"></i> <?php echo t('ivr.new_ivr'); ?></h3>
            <button class="btn btn-secondary" onclick="closeIvrModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_ivr" value="1">
                <input type="hidden" name="ivr_id" id="modal_ivr_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo t('ivr.field_title'); ?></label>
                    <input type="text" name="title" id="modal_title" class="form-control" required placeholder="ör: Ana Karşılama Menüsü">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('internal_number.field'); ?></label>
                    <input type="text" name="internal_number" id="modal_internal_number"
                           class="form-control" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6"
                           placeholder="ör: 1010">
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('internal_number.help'); ?></small>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ivr.field_prompt'); ?></label>
                        <select name="prompt_file" id="modal_prompt_file" class="form-control" required>
                            <option value="custom/welcome"><?php echo t('ivr.default_prompt'); ?></option>
                            <?php foreach ($announcements as $anc): ?>
                                <option value="<?php echo htmlspecialchars($anc['audio_file']); ?>"><?php echo htmlspecialchars($anc['title']); ?> (<?php echo htmlspecialchars($anc['audio_file']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ivr.field_timeout_sec'); ?></label>
                        <input type="number" name="timeout_seconds" id="modal_timeout_seconds" class="form-control" value="10" min="3" max="60">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ivr.field_max_failures'); ?></label>
                        <input type="number" name="max_failures" id="modal_max_failures" class="form-control" value="3" min="1" max="10">
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('ivr.max_failures_help'); ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; margin-top: 8px;">
                            <input type="checkbox" name="allow_direct_dial" id="modal_allow_direct_dial" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span style="font-weight: 600;"><?php echo t('ivr.field_allow_direct_dial'); ?></span>
                        </label>
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('ivr.allow_direct_dial_help'); ?></small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-language"></i> <?php echo t('ivr.field_language'); ?></label>
                    <select name="language" id="modal_language" class="form-control">
                        <option value=""><?php echo t('ivr.language_default'); ?></option>
                        <?php foreach (getAvailableLanguages() as $lang_code): ?>
                            <option value="<?php echo htmlspecialchars($lang_code); ?>"><?php echo htmlspecialchars(LANGUAGE_LABELS[$lang_code] ?? $lang_code); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('ivr.language_help'); ?></small>
                </div>

                <div class="form-group" style="background: rgba(255, 255, 255, 0.03); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 12px;">
                    <label class="form-label" style="font-weight: 700; color: var(--primary);"><i class="fas fa-clock"></i> <?php echo t('ivr.timeout_box_title'); ?></label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <select name="timeout_dest_type" id="modal_timeout_dest_type" class="form-control">
                                <?php foreach ($modules as $m): ?>
                                    <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="timeout_dest_id" id="modal_timeout_dest_id" class="form-control">
                                <option value=""><?php echo t('ivr.loading'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="background: rgba(255, 255, 255, 0.03); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <label class="form-label" style="font-weight: 700; color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> <?php echo t('ivr.invalid_box_title'); ?></label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <select name="invalid_dest_type" id="modal_invalid_dest_type" class="form-control">
                                <?php foreach ($modules as $m): ?>
                                    <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="invalid_dest_id" id="modal_invalid_dest_id" class="form-control">
                                <option value=""><?php echo t('ivr.loading'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('ivr.field_active'); ?></span>
                    </label>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                    <button type="button" class="btn btn-secondary" onclick="closeIvrModal()" title="İptal">
                        <i class="fas fa-times"></i>
                    </button>
                    <button type="submit" class="btn btn-primary" title="Kaydet">
                        <i class="fas fa-save"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- IVR Keypress Entries Modal -->
<div class="modal-overlay" id="ivrEntriesModal">
    <div class="modal-card" style="max-width: 580px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;"><i class="fas fa-th" style="color: var(--primary);"></i> <?php echo t('ivr.entries_modal_title'); ?> <span id="entries_title_label"></span></h3>
            <button class="btn btn-secondary" onclick="closeIvrEntriesModal()" style="padding: 6px 12px;" title="Kapat"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <?php
            // Mevcut tuşlama listesi. Her IVR için ayrı, gizli bir blok basılıyor;
            // openIvrEntriesModal() yalnızca ilgili olanı görünür yapıyor.
            // Liste SUNUCUDA üretiliyor: destBadge() yeniden kullanılabiliyor ve
            // JS tarafında HTML kurulmadığı için XSS yüzeyi oluşmuyor.
            foreach ($ivrs as $ivr_l):
                $entries_l = $entries_by_ivr[$ivr_l['id']] ?? [];
                $digits_l  = implode(',', array_column($entries_l, 'digit'));
            ?>
                <div class="ivr-entries-list" id="entries_list_<?php echo (int) $ivr_l['id']; ?>"
                     data-digits="<?php echo htmlspecialchars($digits_l, ENT_QUOTES); ?>"
                     style="display: none; margin-bottom: 16px;">
                    <?php if (empty($entries_l)): ?>
                        <p style="color: var(--text-muted); font-size: 12px; margin: 0 0 4px;">
                            <?php echo t('ivr.no_entries'); ?>
                        </p>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 6px;">
                            <?php foreach ($entries_l as $en_l): ?>
                                <div style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-color); border-radius: 6px;">
                                    <strong style="font-size: 12px; min-width: 54px;"><?php echo t('ivr.key_label'); ?> <?php echo htmlspecialchars($en_l['digit']); ?>:</strong>
                                    <span style="flex: 1; font-size: 12px;"><?php echo IvrRepository::destBadge($en_l['dest_type'], $en_l['dest_id'], $destResolveCache); ?></span>
                                    <form method="POST" autocomplete="off" style="display: inline; margin: 0;"
                                          onsubmit="return confirm('<?php echo htmlspecialchars(sprintf(t('ivr.entry_delete_confirm'), $en_l['digit']), ENT_QUOTES); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="delete_ivr_entry" value="1">
                                        <input type="hidden" name="entry_id" value="<?php echo (int) $en_l['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="padding: 4px 8px;" title="<?php echo htmlspecialchars(t('ivr.entry_delete_tooltip')); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <p id="entry_overwrite_warning" style="display: none; color: var(--warning); font-size: 12px; margin: 0 0 8px;">
                <i class="fas fa-triangle-exclamation"></i> <?php echo t('ivr.entry_overwrite_warning'); ?>
            </p>

            <form method="POST" autocomplete="off" style="margin-bottom: 20px; background: rgba(255, 255, 255, 0.03); padding: 14px; border-radius: 10px; border: 1px solid var(--border-color);">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_ivr_entry" value="1">
                <input type="hidden" name="ivr_id" id="entries_ivr_id">

                <div style="display: grid; grid-template-columns: 80px 1fr 1fr; gap: 10px; align-items: flex-end;">
                    <div>
                        <label class="form-label"><?php echo t('ivr.field_digit'); ?></label>
                        <select name="digit" class="form-control" required>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                            <option value="7">7</option>
                            <option value="8">8</option>
                            <option value="9">9</option>
                            <option value="0">0</option>
                            <option value="*">*</option>
                            <option value="#">#</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label"><?php echo t('ivr.field_target_type'); ?></label>
                        <select name="dest_type" id="modal_entry_dest_type" class="form-control">
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label"><?php echo t('ivr.field_target'); ?></label>
                        <select name="dest_id" id="modal_entry_dest_id" class="form-control">
                            <option value=""><?php echo t('ivr.loading'); ?></option>
                        </select>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 12px;">
                    <button type="submit" class="btn btn-primary btn-sm" title="<?php echo htmlspecialchars(t('ivr.add_entry_tooltip')); ?>">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/destinations_helper.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/ivrs.js?v=<?php echo time(); ?>"></script>
