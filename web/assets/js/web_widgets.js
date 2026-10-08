/* Page script of templates/views/web_widgets/index.php */

var WIDGET_DEFAULTS = {
    id: 0, name: '', number: '', is_active: 1, dest_type: 'extension', dest_id: '',
    external_number: '', outbound_route_id: 0, allowed_origins: '',
    call_enabled: 1, callback_enabled: 0, callback_prefixes: '', callback_cid: '',
    max_concurrent: 2, max_call_seconds: 900, ip_hourly_limit: 10, daily_limit: 0,
    button_text: '', color: '#2563eb', position: 'right', language: 'en', ask_name: 0
};

function fillWidgetForm(w) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v === null || v === undefined) ? '' : String(v); };
    const check = (id, v) => { const el = document.getElementById(id); if (el) el.checked = String(v) === '1'; };
    set('widget_id', w.id);
    set('widget_name', w.name);
    set('widget_number', w.number);
    set('widget_dest_type', w.dest_type);
    set('widget_external_number', w.external_number);
    set('widget_outbound_route_id', w.outbound_route_id || 0);
    set('widget_allowed_origins', w.allowed_origins);
    set('widget_callback_prefixes', w.callback_prefixes);
    set('widget_callback_cid', w.callback_cid);
    set('widget_max_concurrent', w.max_concurrent);
    set('widget_max_call_seconds', w.max_call_seconds);
    set('widget_ip_hourly_limit', w.ip_hourly_limit);
    set('widget_daily_limit', w.daily_limit);
    set('widget_button_text', w.button_text);
    set('widget_color', w.color);
    set('widget_position', w.position);
    set('widget_language', w.language);
    check('widget_is_active', w.is_active);
    check('widget_call_enabled', w.call_enabled);
    check('widget_callback_enabled', w.callback_enabled);
    check('widget_ask_name', w.ask_name);
    onWidgetDestTypeChange(w.dest_id);
}

function openCreateWidgetModal() {
    document.getElementById('widgetModalTitle').innerHTML = '<i class="fas fa-plus-circle u-primary"></i> ' + escapeHtml(__('js.web_widgets.add'));
    fillWidgetForm(WIDGET_DEFAULTS);
    UIHelper.openOverlayModal('widgetModal');
}

function openEditWidgetModal(w) {
    document.getElementById('widgetModalTitle').innerHTML = '<i class="fas fa-edit u-primary"></i> ' + escapeHtml(__('js.web_widgets.edit') + ' ' + (w.name || ''));
    fillWidgetForm(Object.assign({}, WIDGET_DEFAULTS, w));
    UIHelper.openOverlayModal('widgetModal');
}

/** Shows the fields the chosen destination and the call-back need. */
function onWidgetDestTypeChange(selectedId) {
    const type = document.getElementById('widget_dest_type').value;
    const external = type === 'external';
    const callback = document.getElementById('widget_callback_enabled').checked;
    document.getElementById('widget_dest_id_group').style.display = external ? 'none' : '';
    document.getElementById('widget_external_group').style.display = external ? '' : 'none';
    document.getElementById('widget_external_warning').style.display = (external || callback) ? '' : 'none';
    document.getElementById('widget_callback_group').style.display = callback ? '' : 'none';
    document.getElementById('widget_route_group').style.display = (external || callback) ? '' : 'none';
    if (!external) {
        loadDestinationOptions('widget_dest_type', 'widget_dest_id', typeof selectedId === 'string' ? selectedId : '');
    }
}

function copyWidgetCode(btn) {
    UIHelper.copyToClipboard(btn.getAttribute('data-code') || '', __('js.web_widgets.code_copied'));
}
