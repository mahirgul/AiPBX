/* Page script of templates/views/dial_permissions/index.php */

function openCreateGroupModal() {
    document.getElementById('groupModalTitle').innerHTML = '<i class="fas fa-plus-circle u-primary"></i> Yeni Yetki Grubu Ekle';
    document.getElementById('modal_group_id').value = '0';
    document.getElementById('modal_group_name').value = '';
    document.getElementById('modal_group_desc').value = '';
    document.getElementById('modal_default_action').value = 'allow';
    document.getElementById('modal_group_active').checked = true;
    UIHelper.openOverlayModal('groupModal');
}

function openEditGroupModal(g) {
    document.getElementById('groupModalTitle').innerHTML = '<i class="fas fa-edit u-primary"></i> Yetki Grubu Düzenle: ' + escapeHtml(g.group_name || '');
    document.getElementById('modal_group_id').value = g.id || '0';
    document.getElementById('modal_group_name').value = g.group_name || '';
    document.getElementById('modal_group_desc').value = g.description || '';
    document.getElementById('modal_default_action').value = g.default_action || 'allow';
    document.getElementById('modal_group_active').checked = (g.is_active == 1);
    UIHelper.openOverlayModal('groupModal');
}

function openCreateRuleModal(groupId) {
    document.getElementById('ruleModalTitle').innerHTML = '<i class="fas fa-plus-circle u-primary"></i> Yeni Yetki Kuralı Ekle';
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
    document.getElementById('ruleModalTitle').innerHTML = '<i class="fas fa-edit u-primary"></i> Kural Düzenle: ' + escapeHtml(r.pattern || '');
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
