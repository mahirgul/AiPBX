<?php
/** @var array $providers @var array $engines @var array $local_models @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$capLabel = fn($c) => t('ai_cloud.cap_' . $c);
$langNames = ['tr' => t('ai_cloud.lang_tr'), 'de' => t('ai_cloud.lang_de'), 'en' => t('ai_cloud.lang_en')];
$jobNames = ['tts' => t('ai_cloud.job_tts'), 'stt' => t('ai_cloud.job_stt')];
$configured = array_column(array_filter($providers, fn($p) => $p['configured']), null, 'id');
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-cloud u-primary"></i> <?php echo t('ai_cloud.title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('aiCloudHelpBox')" title="<?php echo t('common.module_guide'); ?>"><i class="fas fa-question-circle"></i></button>
    </div>
    <div class="module-help-box" id="aiCloudHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('ai_cloud.help_title'); ?></h4>
        <?php echo t('ai_cloud.help_body'); ?>
    </div>
    <div style="padding: 0 20px 20px;">
        <p style="color: var(--warning); margin: 0;"><i class="fas fa-shield-halved"></i> <?php echo t('ai_cloud.privacy'); ?></p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-route u-primary"></i> <?php echo t('ai_cloud.engines_title'); ?></div>
    </div>
    <form method="POST" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <p class="u-muted u-fs-12 u-mt-0"><?php echo t('ai_cloud.engines_help'); ?></p>
        <div class="table-responsive"><table class="table"><tbody>
        <?php foreach ($engines as $e):
            $kind = $e['job'];
            $prefix = $e['lang'];
            $locals = array_filter($local_models, fn($m) => ($m['kind'] ?? '') === $kind
                && in_array(true, array_map(fn($l) => str_starts_with(strtolower(str_replace('_', '-', (string) $l)), $prefix), (array) ($m['languages'] ?? [])), true)
                && in_array($m['state'] ?? '', ['ready', 'installed'], true));
            $clouds = array_filter($configured, fn($p) => in_array($kind, $p['caps'], true)); ?>
            <tr>
                <td style="width: 40%;"><strong><?php echo $h($jobNames[$kind]); ?></strong> · <?php echo $h($langNames[$e['lang']]); ?></td>
                <td>
                    <select name="engine_<?php echo $h($kind . '_' . $e['lang']); ?>" class="form-control">
                        <option value=""><?php echo t('ai_cloud.engine_none'); ?></option>
                        <?php if ($locals): ?><optgroup label="<?php echo $h(t('ai_cloud.engine_local')); ?>">
                            <?php foreach ($locals as $m): $v = 'local:' . $m['id']; ?>
                                <option value="<?php echo $h($v); ?>" <?php echo $e['value'] === $v ? 'selected' : ''; ?>><?php echo $h($m['title']); ?></option>
                            <?php endforeach; ?></optgroup><?php endif; ?>
                        <?php if ($clouds): ?><optgroup label="<?php echo $h(t('ai_cloud.engine_cloud')); ?>">
                            <?php foreach ($clouds as $p): $v = 'cloud:' . $p['id']; ?>
                                <option value="<?php echo $h($v); ?>" <?php echo $e['value'] === $v ? 'selected' : ''; ?>><?php echo $h($p['title']); ?></option>
                            <?php endforeach; ?></optgroup><?php endif; ?>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <button type="submit" name="save_engines" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
    </form>
</div>

<div class="u-grid-2" style="gap: 16px;">
<?php foreach ($providers as $p): ?>
    <form method="POST" autocomplete="off" enctype="multipart/form-data" class="card u-mb-0" style="padding: 16px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="save_provider" value="<?php echo $h($p['id']); ?>">
        <div class="u-flex-between u-mb-10">
            <div class="u-strong"><?php echo $h($p['title']); ?></div>
            <span class="badge <?php echo $p['configured'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11"><?php echo t($p['configured'] ? 'ai_tts.configured' : 'ai_tts.not_configured'); ?></span>
        </div>
        <div class="u-mb-10">
            <?php foreach ($p['caps'] as $c): ?><span class="badge badge-info u-fs-11" style="margin-right: 4px;"><?php echo $h($capLabel($c)); ?></span><?php endforeach; ?>
            <?php if ($p['free']): ?><span class="badge badge-secondary u-fs-11"><?php echo t('ai_cloud.free_tier'); ?></span><?php endif; ?>
            <a href="<?php echo $h($p['url']); ?>" target="_blank" rel="noopener" class="u-fs-11" style="margin-left: 6px;"><?php echo t('ai_cloud.get_key'); ?> <i class="fas fa-up-right-from-square"></i></a>
        </div>
        <?php foreach ($p['fields'] as $f): ?>
            <div class="form-group">
                <label class="form-label"><?php echo $h($f['label']); ?><?php echo !empty($f['optional']) ? ' <span class="u-muted u-fs-11">(' . t('ai_tts.optional') . ')</span>' : ''; ?></label>
                <?php if (!empty($f['json'])): ?>
                    <?php if ($f['masked'] !== ''): ?><div class="u-fs-12 u-mb-10"><i class="fas fa-user-shield u-success"></i> <?php echo $h($f['masked']); ?></div><?php endif; ?>
                    <input type="file" name="service_account_file" accept=".json,application/json" class="form-control">
                    <textarea name="<?php echo $h($f['key']); ?>" class="form-control u-mt-4" rows="2" style="font-family: monospace; font-size: 11px;" placeholder="<?php echo $h(t('ai_tts.json_placeholder')); ?>"></textarea>
                <?php elseif ($f['secret']): ?>
                    <input type="password" name="<?php echo $h($f['key']); ?>" class="form-control" autocomplete="new-password"
                           placeholder="<?php echo $h($f['masked'] !== '' ? $f['masked'] . ' — ' . t('ai_tts.keep_secret') : ''); ?>">
                <?php else: ?>
                    <input type="text" name="<?php echo $h($f['key']); ?>" class="form-control" value="<?php echo $h($f['value']); ?>" placeholder="<?php echo $h($f['default'] ?? ''); ?>">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!empty($p['usage'])): $u = $p['usage']; ?>
            <div class="u-muted u-fs-11 u-mb-10"><i class="fas fa-chart-simple"></i> <?php echo $h(sprintf(t('ai_cloud.usage'), number_format($u['stt_seconds'] / 60, 1), number_format($u['tts_chars']), number_format($u['llm_tokens']))); ?></div>
        <?php endif; ?>
        <div class="u-flex-gap">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
            <?php if ($p['configured']): ?>
                <button type="button" class="btn btn-secondary btn-sm" data-test="<?php echo $h($p['id']); ?>"><i class="fas fa-plug-circle-check"></i> <?php echo t('ai_tts.btn_test'); ?></button>
                <button type="submit" name="clear" value="1" class="btn btn-danger btn-sm" onclick="return confirm(<?php echo $h(json_encode(t('ai_tts.confirm_clear'))); ?>)"><i class="fas fa-trash-alt"></i></button>
            <?php endif; ?>
        </div>
    </form>
<?php endforeach; ?>
</div>

<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-test]');
    if (!btn) return;
    btn.disabled = true;
    var body = new URLSearchParams({ action: 'test', provider: btn.dataset.test, csrf_token: window.CSRF_TOKEN || '' });
    fetch('/api/ai_cloud.php', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false;
        var msg = d.success ? d.message : (d.error || 'Error');
        if (window.showFooterToast) window.showFooterToast(msg, d.success ? 'success' : 'error'); else alert(msg);
    }).catch(function () { btn.disabled = false; });
});
</script>
