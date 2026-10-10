<?php
/** @var array $apps @var array $voices @var array $stt_models @var array $requests @var array $modules @var bool $service_ok @var string $csrf_token */
use PBX\Destinations\DestinationRegistry;

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$labelCache = [];
$voiceTitles = array_column($voices, 'title', 'id');
$engineTitle = fn($l) => sprintf(t('ai_apps.voice_engine'), t('ai_cloud.lang_' . $l));
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-robot u-primary"></i> <?php echo t('ai_apps.title'); ?></div>
        <div class="u-flex-gap">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('aiAppsHelpBox')" title="<?php echo t('common.module_guide'); ?>"><i class="fas fa-question-circle"></i></button>
            <button type="button" class="btn btn-primary btn-sm" onclick="aiAppEdit(null)"><i class="fas fa-plus"></i> <?php echo t('ai_apps.btn_new'); ?></button>
        </div>
    </div>
    <div class="module-help-box" id="aiAppsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('ai_apps.help_title'); ?></h4>
        <?php echo t('ai_apps.help_body'); ?>
    </div>
    <?php if (!$service_ok): ?>
        <p style="color: var(--warning); padding: 0 20px;"><i class="fas fa-triangle-exclamation"></i> <?php echo t('ai_apps.service_down'); ?> <a href="/ai-models"><?php echo t('sidebar.item_ai_models'); ?></a></p>
    <?php endif; ?>
    <div class="table-responsive" style="padding: 0 20px 20px;">
        <table class="table">
            <thead><tr>
                <th><?php echo t('ai_apps.col_title'); ?></th><th><?php echo t('ai_apps.col_number'); ?></th>
                <th><?php echo t('ai_apps.col_voice'); ?></th><th><?php echo t('ai_apps.col_dest'); ?></th>
                <th><?php echo t('ai_apps.col_active'); ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$apps): ?><tr><td colspan="6" class="u-muted"><?php echo t('ai_apps.none'); ?></td></tr><?php endif; ?>
            <?php foreach ($apps as $a):
                $voice = str_starts_with($a['model_id'], 'engine:') ? $engineTitle(substr($a['model_id'], 7)) : ($voiceTitles[$a['model_id']] ?? $a['model_id']);
                $mod = DestinationRegistry::getModule($a['dest_type']);
                $dest = ($mod ? $mod->getName() : $a['dest_type']) . ($a['dest_id'] !== '' ? ': ' . (DestinationRegistry::resolveLabel($a['dest_type'], $a['dest_id'], $labelCache) ?? $a['dest_id']) : ''); ?>
                <tr>
                    <td><strong><?php echo $h($a['title']); ?></strong><div class="u-muted u-fs-11"><?php echo $h(mb_strimwidth($a['text_template'], 0, 90, '…')); ?></div></td>
                    <td><?php echo $a['internal_number'] ? '<span class="badge badge-info">' . $h($a['internal_number']) . '</span>' : '—'; ?></td>
                    <td class="u-fs-12"><?php echo $h($voice); ?></td>
                    <td class="u-fs-12"><?php echo $h($dest); ?></td>
                    <td><?php echo (int) $a['is_active'] ? '<span class="badge badge-success">' . t('common.active') . '</span>' : '<span class="badge badge-secondary">' . t('common.passive') . '</span>'; ?></td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick='aiAppEdit(<?php echo $h(json_encode($a, JSON_UNESCAPED_UNICODE)); ?>)'><i class="fas fa-pen"></i></button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm(<?php echo $h(json_encode(t('ai_apps.confirm_delete'))); ?>)">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <button type="submit" name="delete_app" value="<?php echo (int) $a['id']; ?>" class="btn btn-danger btn-sm"><i class="fas fa-trash-alt"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="aiAppFormCard" style="display: none;">
    <div class="card-header"><div class="card-title"><i class="fas fa-pen u-primary"></i> <span id="aiAppFormTitle"><?php echo t('ai_apps.btn_new'); ?></span></div></div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;" id="aiAppForm">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="id" id="ai_id" value="0">
        <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_type'); ?></label>
            <select name="app_type" id="ai_type" class="form-control" style="max-width: 420px;" onchange="aiAppType()">
                <option value="announcement"><?php echo t('ai_apps.type_announcement'); ?></option>
                <option value="voice_requests"><?php echo t('ai_apps.type_voice_requests'); ?></option>
            </select></div>
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_title'); ?></label>
                <input type="text" name="title" id="ai_title" class="form-control" maxlength="100" required></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_number'); ?></label>
                <input type="text" name="internal_number" id="ai_number" class="form-control" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6" placeholder="7201">
                <small class="u-hint u-fs-11"><?php echo t('ai_apps.number_help'); ?></small></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_voice'); ?></label>
                <select name="model_id" id="ai_model" class="form-control">
                    <optgroup label="<?php echo $h(t('ai_apps.voice_engines')); ?>">
                        <?php foreach (['tr', 'de', 'en'] as $l): ?><option value="engine:<?php echo $l; ?>"><?php echo $h($engineTitle($l)); ?></option><?php endforeach; ?>
                    </optgroup>
                    <?php if ($voices): ?><optgroup label="<?php echo $h(t('ai_cloud.engine_local')); ?>">
                        <?php foreach ($voices as $v): ?><option value="<?php echo $h($v['id']); ?>"><?php echo $h($v['title'] . ' (' . implode(', ', (array) ($v['languages'] ?? [])) . ')'); ?></option><?php endforeach; ?>
                    </optgroup><?php endif; ?>
                </select></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_speed'); ?></label>
                <input type="number" name="speed" id="ai_speed" class="form-control" min="0.5" max="2" step="0.05" value="1"></div>
        </div>
        <div class="ai-only-announcement">
        <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_text'); ?></label>
            <textarea name="text_template" id="ai_text" class="form-control" rows="3" maxlength="2000" required placeholder="<?php echo $h(t('ai_apps.text_placeholder')); ?>"></textarea>
            <small class="u-hint u-fs-11"><?php echo t('ai_apps.text_help'); ?></small></div>
        <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_lookup'); ?></label>
            <input type="url" name="lookup_url" id="ai_lookup" class="form-control" maxlength="500" placeholder="https://crm.example.com/aipbx/balance">
            <small class="u-hint u-fs-11"><?php echo t('ai_apps.lookup_help'); ?></small></div>
        <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_fallback'); ?></label>
            <textarea name="fallback_text" id="ai_fallback" class="form-control" rows="2" maxlength="2000"></textarea>
            <small class="u-hint u-fs-11"><?php echo t('ai_apps.fallback_help'); ?></small></div>
        </div>
        <div class="ai-only-voice_requests">
            <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_greeting'); ?></label>
                <textarea name="greeting" id="ai_greeting" class="form-control" rows="2" maxlength="1000" placeholder="<?php echo $h(t('ai_apps.greeting_placeholder')); ?>"></textarea></div>
            <div class="u-grid-2">
                <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_retry'); ?></label>
                    <input type="text" name="retry_text" id="ai_retry" class="form-control" maxlength="1000" placeholder="<?php echo $h(t('ai_apps.retry_placeholder')); ?>"></div>
                <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_not_understood'); ?></label>
                    <input type="text" name="not_understood_text" id="ai_nu" class="form-control" maxlength="1000" placeholder="<?php echo $h(t('ai_apps.not_understood_placeholder')); ?>"></div>
                <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_stt'); ?></label>
                    <select name="stt_model" id="ai_stt" class="form-control">
                        <optgroup label="<?php echo $h(t('ai_apps.voice_engines')); ?>">
                            <?php foreach (['tr', 'de', 'en'] as $l): ?><option value="engine:<?php echo $l; ?>"><?php echo $h(sprintf(t('ai_apps.stt_engine'), t('ai_cloud.lang_' . $l))); ?></option><?php endforeach; ?>
                        </optgroup>
                        <?php if ($stt_models): ?><optgroup label="<?php echo $h(t('ai_cloud.engine_local')); ?>">
                            <?php foreach ($stt_models as $m): ?><option value="<?php echo $h($m['id']); ?>"><?php echo $h($m['title']); ?></option><?php endforeach; ?>
                        </optgroup><?php endif; ?>
                    </select></div>
                <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_listen'); ?></label>
                    <input type="number" name="listen_seconds" id="ai_listen" class="form-control" min="2" max="15" value="7"></div>
                <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_retries'); ?></label>
                    <input type="number" name="retries" id="ai_retries" class="form-control" min="0" max="2" value="1"></div>
                <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_threshold'); ?></label>
                    <input type="number" name="threshold" id="ai_threshold" class="form-control" min="0" max="1" step="0.05" value="0.5">
                    <small class="u-hint u-fs-11"><?php echo t('ai_apps.threshold_help'); ?></small></div>
            </div>
            <label class="form-label"><?php echo t('ai_apps.field_intents'); ?></label>
            <p class="u-muted u-fs-11 u-mt-0"><?php echo t('ai_apps.intents_help'); ?></p>
            <div id="ai_intents"></div>
            <button type="button" class="btn btn-secondary btn-sm u-mb-10" onclick="aiIntentAdd()"><i class="fas fa-plus"></i> <?php echo t('ai_apps.btn_add_intent'); ?></button>
        </div>
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><span class="ai-only-announcement"><?php echo t('ai_apps.field_dest'); ?></span><span class="ai-only-voice_requests"><?php echo t('ai_apps.field_dest_nu'); ?></span></label>
                <select name="dest_type" id="ai_dest_type" class="form-control">
                    <?php foreach ($modules as $m): ?><option value="<?php echo $h($m['key']); ?>"><?php echo $h($m['name']); ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-group"><label class="form-label">&nbsp;</label>
                <select name="dest_id" id="ai_dest_id" class="form-control"></select></div>
            <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_max'); ?></label>
                <input type="number" name="max_concurrent" id="ai_max" class="form-control" min="1" max="50" value="4">
                <small class="u-hint u-fs-11"><?php echo t('ai_apps.max_help'); ?></small></div>
            <div class="form-group"><label class="u-check-label-6" style="margin-top: 28px; display: block;">
                <input type="checkbox" name="is_active" id="ai_active" value="1" class="u-accent" checked> <?php echo t('common.active'); ?></label></div>
        </div>
        <div class="u-flex-gap">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('aiAppFormCard').style.display='none'"><?php echo t('common.cancel'); ?></button>
        </div>
    </form>
</div>

<?php if ($requests): ?>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-list-check u-primary"></i> <?php echo t('ai_apps.requests_title'); ?></div></div>
    <div class="table-responsive" style="padding: 0 20px 20px;">
        <table class="table">
            <thead><tr><th><?php echo t('ai_apps.col_time'); ?></th><th><?php echo t('ai_apps.col_caller'); ?></th><th><?php echo t('ai_apps.col_request'); ?></th>
                <th><?php echo t('ai_apps.col_said'); ?></th><th></th><th><?php echo t('ai_apps.col_handled'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td class="u-fs-12" style="white-space: nowrap;"><?php echo $h(date('d.m H:i', strtotime($r['created_at']))); ?></td>
                    <td class="u-fs-12"><strong><?php echo $h($r['caller']); ?></strong> <?php echo $h($r['caller_name']); ?><div class="u-muted u-fs-11"><?php echo $h($r['app_title'] ?? ''); ?></div></td>
                    <td><?php echo $r['intent_id'] === 'none' ? '<span class="badge badge-warning">' . t('ai_apps.not_understood') . '</span>' : '<span class="badge badge-info">' . $h($r['intent_name']) . '</span>'; ?>
                        <?php if ((int) $r['mail_sent']): ?><i class="fas fa-envelope u-success" title="<?php echo $h(t('ai_apps.mail_sent')); ?>"></i><?php endif; ?></td>
                    <td class="u-fs-12"><?php echo $h($r['transcript']); ?></td>
                    <td><audio controls preload="none" src="/api/ai_apps.php?action=audio&uuid=<?php echo $h($r['call_uuid']); ?>" style="height: 28px; width: 160px;"></audio></td>
                    <td><input type="checkbox" class="u-accent" data-handled="<?php echo (int) $r['id']; ?>" <?php echo $r['handled_at'] ? 'checked' : ''; ?>></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/destinations_helper.js'); ?>"></script>
<script>
window.AI_APPS_TEXT = { edit: <?php echo json_encode(t('ai_apps.edit')); ?>, create: <?php echo json_encode(t('ai_apps.btn_new')); ?> };
function aiAppEdit(a) {
    const f = (id, v) => { const el = document.getElementById(id); if (el.type === 'checkbox') el.checked = !!Number(v); else el.value = v; };
    a = a || { id: 0, title: '', internal_number: '', model_id: 'engine:tr', speed: '1', text_template: '', lookup_url: '', fallback_text: '', dest_type: 'hangup', dest_id: '', max_concurrent: 4, is_active: 1 };
    f('ai_id', a.id); f('ai_title', a.title); f('ai_number', a.internal_number || ''); f('ai_speed', a.speed);
    f('ai_text', a.text_template); f('ai_lookup', a.lookup_url || ''); f('ai_fallback', a.fallback_text || '');
    f('ai_max', a.max_concurrent); f('ai_active', a.is_active);
    const m = document.getElementById('ai_model');
    if (![...m.options].some(o => o.value === a.model_id)) { const o = new Option(a.model_id, a.model_id); m.add(o); }
    m.value = a.model_id;
    document.getElementById('ai_type').value = a.app_type || 'announcement';
    const c = a.config ? (typeof a.config === 'string' ? JSON.parse(a.config) : a.config) : {};
    f('ai_greeting', c.greeting || ''); f('ai_retry', c.retry || ''); f('ai_nu', c.not_understood || '');
    f('ai_listen', c.listen_seconds || 7); f('ai_retries', c.retries == null ? 1 : c.retries); f('ai_threshold', c.threshold == null ? 0.5 : c.threshold);
    const st = document.getElementById('ai_stt');
    if (c.stt_model && ![...st.options].some(o => o.value === c.stt_model)) st.add(new Option(c.stt_model, c.stt_model));
    st.value = c.stt_model || 'engine:tr';
    document.getElementById('ai_intents').innerHTML = '';
    (c.intents || []).forEach(i => aiIntentAdd(i));
    if (!(c.intents || []).length) aiIntentAdd();
    aiAppType();
    document.getElementById('ai_dest_type').value = a.dest_type;
    loadDestinationOptions('ai_dest_type', 'ai_dest_id', a.dest_id || '');
    document.getElementById('aiAppFormTitle').textContent = a.id ? window.AI_APPS_TEXT.edit + ': ' + a.title : window.AI_APPS_TEXT.create;
    const card = document.getElementById('aiAppFormCard');
    card.style.display = '';
    card.scrollIntoView({ behavior: 'smooth' });
}
bindDestinationSelector('ai_dest_type', 'ai_dest_id');

const AI_DEST_MODULES = <?php echo json_encode(array_map(fn($m) => ['key' => $m['key'], 'name' => $m['name']], $modules), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const AI_T = <?php echo json_encode(['name' => t('ai_apps.intent_name'), 'keywords' => t('ai_apps.intent_keywords'), 'examples' => t('ai_apps.intent_examples'),
    'reply' => t('ai_apps.intent_reply'), 'email' => t('ai_apps.intent_email'), 'dest' => t('ai_apps.intent_dest'), 'none' => t('ai_apps.intent_dest_none'),
    'remove' => t('common.delete')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
let aiIntentSeq = 0;
function aiAppType() {
    const t = document.getElementById('ai_type').value;
    document.querySelectorAll('.ai-only-announcement').forEach(el => el.style.display = t === 'announcement' ? '' : 'none');
    document.querySelectorAll('.ai-only-voice_requests').forEach(el => el.style.display = t === 'voice_requests' ? '' : 'none');
    document.getElementById('ai_text').required = t === 'announcement';
    document.getElementById('ai_greeting').required = t === 'voice_requests';
}
function aiIntentAdd(i) {
    i = i || { id: '', name: '', keywords: [], examples: [], reply: '', email: '', dest_type: '', dest_id: '' };
    const n = aiIntentSeq++;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const row = document.createElement('div');
    row.className = 'card';
    row.style.cssText = 'padding: 12px; margin-bottom: 10px;';
    const field = (k, label, html) => '<div class="form-group" style="margin-bottom: 8px;"><label class="form-label u-fs-12">' + esc(label) + '</label>' + html + '</div>';
    row.innerHTML = '<input type="hidden" name="intents[' + n + '][id]" value="' + esc(i.id) + '">'
        + '<div class="u-grid-2">'
        + field('name', AI_T.name, '<input type="text" class="form-control" name="intents[' + n + '][name]" maxlength="100" value="' + esc(i.name) + '">')
        + field('keywords', AI_T.keywords, '<input type="text" class="form-control" name="intents[' + n + '][keywords]" value="' + esc((i.keywords || []).join(', ')) + '">')
        + '</div>'
        + field('examples', AI_T.examples, '<textarea class="form-control" rows="2" name="intents[' + n + '][examples]">' + esc((i.examples || []).join('\n')) + '</textarea>')
        + field('reply', AI_T.reply, '<input type="text" class="form-control" name="intents[' + n + '][reply]" maxlength="1000" value="' + esc(i.reply) + '">')
        + '<div class="u-grid-2">'
        + field('email', AI_T.email, '<input type="email" class="form-control" name="intents[' + n + '][email]" value="' + esc(i.email) + '">')
        + field('dest', AI_T.dest, '<div class="u-flex-gap"><select class="form-control" id="ai_idt_' + n + '" name="intents[' + n + '][dest_type]"><option value="">' + esc(AI_T.none) + '</option>'
            + AI_DEST_MODULES.map(m => '<option value="' + esc(m.key) + '">' + esc(m.name) + '</option>').join('') + '</select>'
            + '<select class="form-control" id="ai_idi_' + n + '" name="intents[' + n + '][dest_id]"></select></div>')
        + '</div>'
        + '<button type="button" class="btn btn-danger btn-sm" onclick="this.closest(\'.card\').remove()"><i class="fas fa-trash-alt"></i> ' + esc(AI_T.remove) + '</button>';
    document.getElementById('ai_intents').appendChild(row);
    const dt = document.getElementById('ai_idt_' + n);
    dt.value = i.dest_type || '';
    bindDestinationSelector('ai_idt_' + n, 'ai_idi_' + n);
    if (i.dest_type) loadDestinationOptions('ai_idt_' + n, 'ai_idi_' + n, i.dest_id || '');
}
document.addEventListener('change', e => {
    const cb = e.target.closest('input[data-handled]');
    if (!cb) return;
    fetch('/api/ai_apps.php', { method: 'POST', body: new URLSearchParams({ action: 'handled', id: cb.dataset.handled, handled: cb.checked ? 1 : 0, csrf_token: window.CSRF_TOKEN || '' }) });
});
</script>
