<?php
/** @var array $apps @var array $voices @var array $modules @var bool $service_ok @var string $csrf_token */
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
        <input type="hidden" name="app_type" value="announcement">
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
        <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_text'); ?></label>
            <textarea name="text_template" id="ai_text" class="form-control" rows="3" maxlength="2000" required placeholder="<?php echo $h(t('ai_apps.text_placeholder')); ?>"></textarea>
            <small class="u-hint u-fs-11"><?php echo t('ai_apps.text_help'); ?></small></div>
        <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_lookup'); ?></label>
            <input type="url" name="lookup_url" id="ai_lookup" class="form-control" maxlength="500" placeholder="https://crm.example.com/aipbx/balance">
            <small class="u-hint u-fs-11"><?php echo t('ai_apps.lookup_help'); ?></small></div>
        <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_fallback'); ?></label>
            <textarea name="fallback_text" id="ai_fallback" class="form-control" rows="2" maxlength="2000"></textarea>
            <small class="u-hint u-fs-11"><?php echo t('ai_apps.fallback_help'); ?></small></div>
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><?php echo t('ai_apps.field_dest'); ?></label>
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
    document.getElementById('ai_dest_type').value = a.dest_type;
    loadDestinationOptions('ai_dest_type', 'ai_dest_id', a.dest_id || '');
    document.getElementById('aiAppFormTitle').textContent = a.id ? window.AI_APPS_TEXT.edit + ': ' + a.title : window.AI_APPS_TEXT.create;
    const card = document.getElementById('aiAppFormCard');
    card.style.display = '';
    card.scrollIntoView({ behavior: 'smooth' });
}
bindDestinationSelector('ai_dest_type', 'ai_dest_id');
</script>
