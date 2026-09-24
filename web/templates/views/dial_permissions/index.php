<?php
/**
 * Calling Permission Groups & Rules View
 */
$selected_group = null;
foreach ($groups as $g) {
    if ((int)$g['id'] === (int)$selected_group_id) {
        $selected_group = $g;
        break;
    }
}
if (!$selected_group && !empty($groups)) {
    $selected_group = $groups[0];
    $selected_group_id = (int)$selected_group['id'];
}
?>

<div class="card mb-3">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-shield-alt" style="color: var(--primary);"></i> <?php echo t('dial_permissions.title', 'Arama Yetki Grupları'); ?>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('permHelpBox')" title="<?php echo t('common.module_guide', 'Modül Rehberi'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('dial_permissions', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateGroupModal()" title="<?php echo t('dial_permissions.new_group_btn', 'Yeni Yetki Grubu'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="permHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('dial_permissions.help_title', 'Arama Yetki Grupları Nasıl Çalışır?'); ?></h4>
        <p><?php echo t('dial_permissions.help_body', 'Arama yetki grupları, dahili abonelerin harici hatlara doğru yapacakları aramaları denetler.'); ?></p>
        <ul>
            <li><strong><?php echo t('dial_permissions.help_default', 'Varsayılan Davranış:'); ?></strong> <?php echo t('dial_permissions.help_default_desc', 'Grup "İzin Ver" modundaysa kurallar kara liste (engelleme), "Engelle" modundaysa kurallar beyaz liste (izin verme) olarak çalışır.'); ?></li>
            <li><strong><?php echo t('dial_permissions.help_prefix', 'Ön Ek (Prefix):'); ?></strong> <?php echo t('dial_permissions.help_prefix_desc', 'Örneğin "0" girilirse 0 ile başlayan tüm numaralar, "00" girilirse yurtdışı, "05" girilirse tüm GSM aramaları kapsanır.'); ?></li>
            <li><strong><?php echo t('dial_permissions.help_exact', 'Tam Numara:'); ?></strong> <?php echo t('dial_permissions.help_exact_desc', 'Spesifik bir numara girildiğinde sadece o numara için kural işletilir.'); ?></li>
        </ul>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 340px 1fr; gap: 20px; align-items: start;">
    <!-- Sol Kolon: Yetki Grupları Listesi -->
    <div class="card" style="padding: 16px;">
        <div class="card-header" style="margin-bottom: 14px;">
            <div class="card-title">
                <i class="fas fa-layer-group" style="color: var(--primary);"></i> <?php echo t('dial_permissions.groups_list', 'Yetki Grupları'); ?>
            </div>
            <span class="badge badge-secondary"><?php echo count($groups); ?></span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 8px;">
            <?php foreach ($groups as $g): ?>
                <?php 
                $isSelected = ((int)$g['id'] === (int)$selected_group_id);
                $isDefault = ((int)$g['id'] === 1);
                ?>
                <div style="padding: 12px 14px; border-radius: 10px; border: 1px solid <?php echo $isSelected ? 'var(--primary)' : 'var(--border-color)'; ?>; background: <?php echo $isSelected ? 'rgba(var(--primary-rgb, 2, 132, 199), 0.08)' : 'var(--bg-card)'; ?>; cursor: pointer; transition: all 0.2s;" onclick="location.href='/dial-permissions?group_id=<?php echo $g['id']; ?>'">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <span style="font-weight: 700; font-size: 14px; color: var(--text-main);">
                            <?php echo htmlspecialchars($g['group_name']); ?>
                            <?php if ($isDefault): ?>
                                <span class="badge badge-info" style="font-size: 10px;"><?php echo t('dial_permissions.default_badge', 'Varsayılan'); ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if ($g['default_action'] === 'allow'): ?>
                            <span class="badge badge-success" style="font-size: 10px;"><?php echo t('dial_permissions.default_allow', 'Genel İzin'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger" style="font-size: 10px;"><?php echo t('dial_permissions.default_deny', 'Genel Engel'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($g['description'])): ?>
                        <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px;"><?php echo htmlspecialchars($g['description']); ?></div>
                    <?php endif; ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: var(--text-muted);">
                        <span><i class="fas fa-list-ol"></i> <?php echo (int)$g['rules_count']; ?> kural</span>
                        <span><i class="fas fa-phone-alt"></i> <?php echo (int)$g['users_count']; ?> abone</span>
                        <?php if (hasModulePermission('dial_permissions', 'edit')): ?>
                            <div style="display: inline-flex; gap: 4px;" onclick="event.stopPropagation();">
                                <button type="button" class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 10px;" onclick='openEditGroupModal(<?php echo json_encode($g); ?>)' title="Düzenle">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if (!$isDefault): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Bu yetki grubunu silmek istediğinizden emin misiniz?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="delete_group" value="1">
                                        <input type="hidden" name="group_id" value="<?php echo $g['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="padding: 2px 6px; font-size: 10px;" title="Sil">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Sağ Kolon: Seçili Grubun Kuralları -->
    <div class="card" style="padding: 20px;">
        <div class="card-header" style="margin-bottom: 16px;">
            <div>
                <div class="card-title">
                    <i class="fas fa-sliders-h" style="color: var(--primary);"></i> 
                    <span><?php echo htmlspecialchars($selected_group['group_name'] ?? ''); ?></span>
                    <span style="font-size: 13px; font-weight: normal; color: var(--text-muted); margin-left: 8px;">
                        (<?php echo t('dial_permissions.base_mode', 'Temel Mod:'); ?> 
                        <strong><?php echo ($selected_group['default_action'] ?? 'allow') === 'allow' ? 'Kara Liste (Varsayılan İzin Ver)' : 'Beyaz Liste (Varsayılan Engelle)'; ?></strong>)
                    </span>
                </div>
            </div>
            <?php if (hasModulePermission('dial_permissions', 'edit') && $selected_group): ?>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-primary btn-sm" onclick="openCreateRuleModal(<?php echo $selected_group['id']; ?>)">
                        <i class="fas fa-plus"></i> <?php echo t('dial_permissions.add_rule_btn', 'Yeni Kural Ekle'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <?php if (hasModulePermission('dial_permissions', 'edit') && $selected_group): ?>
            <!-- Hızlı Kural Ekleme Butonları -->
            <div style="background: var(--bg-input); padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px;">
                <span style="font-weight: 600; color: var(--text-main);"><i class="fas fa-bolt text-warning"></i> <?php echo t('dial_permissions.quick_rules', 'Hızlı Kurallar:'); ?></span>
                <button type="button" class="btn btn-secondary btn-sm" onclick="quickAddRule(<?php echo $selected_group['id']; ?>, '0', 'prefix', 'deny', 'Şehirlerarası/GSM/Yurtdışı Engelle')" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-ban text-danger"></i> 0 ile Başlayanlar (Dış Hat)
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="quickAddRule(<?php echo $selected_group['id']; ?>, '05', 'prefix', 'deny', 'Tüm Cep Telefonlarını Engelle')" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-ban text-danger"></i> 05... (GSM Engelle)
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="quickAddRule(<?php echo $selected_group['id']; ?>, '00', 'prefix', 'deny', 'Uluslararası Aramaları Engelle')" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-ban text-danger"></i> 00... (Yurtdışı Engelle)
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="quickAddRule(<?php echo $selected_group['id']; ?>, '0900', 'prefix', 'deny', 'Özel Ücretli Hatları Engelle')" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-ban text-danger"></i> 0900... (Ücretli Hatlar)
                </button>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70px;"><?php echo t('dial_permissions.col_priority', 'Sıra'); ?></th>
                        <th><?php echo t('dial_permissions.col_pattern', 'Numara / Kalıp'); ?></th>
                        <th><?php echo t('dial_permissions.col_type', 'Eşleşme Tipi'); ?></th>
                        <th><?php echo t('dial_permissions.col_action', 'İşlem'); ?></th>
                        <th><?php echo t('dial_permissions.col_desc', 'Açıklama'); ?></th>
                        <th class="text-right" style="width: 100px;"><?php echo t('dial_permissions.col_actions', 'İşlemler'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rules)): ?>
                        <?php echo uiTableEmptyRow(6, t('dial_permissions.no_rules', 'Bu grupta henüz tanımlı kural bulunmuyor. Temel mod geçerlidir.'), 'fa-filter'); ?>
                    <?php else: ?>
                        <?php foreach ($rules as $r): ?>
                            <tr>
                                <td><span class="badge badge-secondary"><?php echo (int)$r['priority']; ?></span></td>
                                <td>
                                    <strong style="font-size: 14px; letter-spacing: 0.5px; font-family: monospace;">
                                        <?php echo htmlspecialchars($r['pattern']); ?>
                                        <?php if ($r['pattern_type'] === 'prefix'): ?>*<?php endif; ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($r['pattern_type'] === 'prefix'): ?>
                                        <span class="badge badge-info"><i class="fas fa-arrow-right"></i> <?php echo t('dial_permissions.type_prefix', 'Ön Ek (Prefix)'); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><i class="fas fa-equals"></i> <?php echo t('dial_permissions.type_exact', 'Tam Eşleşme'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['action'] === 'allow'): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo t('dial_permissions.action_allow', 'İZİN VER'); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><i class="fas fa-ban"></i> <?php echo t('dial_permissions.action_deny', 'ENGELLE'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars($r['description'] ?? '-'); ?></td>
                                <td class="text-right">
                                    <?php if (hasModulePermission('dial_permissions', 'edit')): ?>
                                        <div style="display: inline-flex; gap: 4px;">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick='openEditRuleModal(<?php echo json_encode($r); ?>)' title="Düzenle">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bu kuralı silmek istediğinizden emin misiniz?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                                <input type="hidden" name="delete_rule" value="1">
                                                <input type="hidden" name="rule_id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Sil">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Yetki Grubu Ekle / Düzenle -->
<div class="modal-overlay" id="groupModal">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="groupModalTitle"><i class="fas fa-shield-alt" style="color: var(--primary);"></i> Yetki Grubu</h3>
            <button class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('groupModal')" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_group" value="1">
                <input type="hidden" name="id" id="modal_group_id" value="0">

                <div class="form-group">
                    <label class="form-label">Grup Adı</label>
                    <input type="text" name="group_name" id="modal_group_name" class="form-control" placeholder="ör: Sadece Şehiriçi ve GSM" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Açıklama</label>
                    <input type="text" name="description" id="modal_group_desc" class="form-control" placeholder="Bu grubun yetki kapsamı hakkında açıklama">
                </div>

                <div class="form-group">
                    <label class="form-label">Varsayılan Temel Davranış (Kural Dışı Aramalar)</label>
                    <select name="default_action" id="modal_default_action" class="form-control">
                        <option value="allow">Varsayılan: İzin Ver (Kara Liste Modu - Sadece engellenenler kısıtlanır)</option>
                        <option value="deny">Varsayılan: Engelle (Beyaz Liste Modu - Sadece izin verilenler aranabilir)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_group_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;">Aktif</span>
                    </label>
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('groupModal')", t('common.save', 'Kaydet'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Kural Ekle / Düzenle -->
<div class="modal-overlay" id="ruleModal">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="ruleModalTitle"><i class="fas fa-filter" style="color: var(--primary);"></i> Yetki Kuralı</h3>
            <button class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('ruleModal')" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="ruleForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_rule" value="1">
                <input type="hidden" name="id" id="modal_rule_id" value="0">
                <input type="hidden" name="group_id" id="modal_rule_group_id" value="<?php echo $selected_group_id; ?>">

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Numara veya Ön Ek (Prefix)</label>
                        <input type="text" name="pattern" id="modal_rule_pattern" class="form-control" placeholder="ör: 05 veya 00 veya 905551234567" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Eşleşme Tipi</label>
                        <select name="pattern_type" id="modal_rule_pattern_type" class="form-control">
                            <option value="prefix">Ön Ek (Bununla Başlayanlar)</option>
                            <option value="exact">Tam Numara (Birebir Eşleşme)</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">İşlem / Karar</label>
                        <select name="action" id="modal_rule_action" class="form-control">
                            <option value="deny">Engelle (Aramaya İzin Verme)</option>
                            <option value="allow">İzin Ver (Aramayı Başlat)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Öncelik Sırası</label>
                        <input type="number" name="priority" id="modal_rule_priority" class="form-control" value="10" min="1" max="999">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Açıklama (Opsiyonel)</label>
                    <input type="text" name="description" id="modal_rule_desc" class="form-control" placeholder="ör: GSM aramaları engeli">
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('ruleModal')", t('common.save', 'Kaydet'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script>
function openCreateGroupModal() {
    document.getElementById('groupModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Yetki Grubu Ekle';
    document.getElementById('modal_group_id').value = '0';
    document.getElementById('modal_group_name').value = '';
    document.getElementById('modal_group_desc').value = '';
    document.getElementById('modal_default_action').value = 'allow';
    document.getElementById('modal_group_active').checked = true;
    UIHelper.openOverlayModal('groupModal');
}

function openEditGroupModal(g) {
    document.getElementById('groupModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Yetki Grubu Düzenle: ' + escapeHtml(g.group_name || '');
    document.getElementById('modal_group_id').value = g.id || '0';
    document.getElementById('modal_group_name').value = g.group_name || '';
    document.getElementById('modal_group_desc').value = g.description || '';
    document.getElementById('modal_default_action').value = g.default_action || 'allow';
    document.getElementById('modal_group_active').checked = (g.is_active == 1);
    UIHelper.openOverlayModal('groupModal');
}

function openCreateRuleModal(groupId) {
    document.getElementById('ruleModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Yetki Kuralı Ekle';
    document.getElementById('modal_rule_id').value = '0';
    document.getElementById('modal_rule_group_id').value = groupId;
    document.getElementById('modal_rule_pattern').value = '';
    document.getElementById('modal_rule_pattern_type').value = 'prefix';
    document.getElementById('modal_rule_action').value = 'deny';
    document.getElementById('modal_rule_priority').value = '10';
    document.getElementById('modal_rule_desc').value = '';
    UIHelper.openOverlayModal('ruleModal');
}

function openEditRuleModal(r) {
    document.getElementById('ruleModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Kural Düzenle: ' + escapeHtml(r.pattern || '');
    document.getElementById('modal_rule_id').value = r.id || '0';
    document.getElementById('modal_rule_group_id').value = r.group_id;
    document.getElementById('modal_rule_pattern').value = r.pattern || '';
    document.getElementById('modal_rule_pattern_type').value = r.pattern_type || 'prefix';
    document.getElementById('modal_rule_action').value = r.action || 'deny';
    document.getElementById('modal_rule_priority').value = r.priority || '10';
    document.getElementById('modal_rule_desc').value = r.description || '';
    UIHelper.openOverlayModal('ruleModal');
}

function quickAddRule(groupId, pattern, type, action, desc) {
    document.getElementById('modal_rule_id').value = '0';
    document.getElementById('modal_rule_group_id').value = groupId;
    document.getElementById('modal_rule_pattern').value = pattern;
    document.getElementById('modal_rule_pattern_type').value = type;
    document.getElementById('modal_rule_action').value = action;
    document.getElementById('modal_rule_priority').value = '10';
    document.getElementById('modal_rule_desc').value = desc;
    document.getElementById('ruleForm').submit();
}
</script>
