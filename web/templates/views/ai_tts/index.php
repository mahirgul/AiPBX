<?php
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$localEnabled = class_exists('PluginManager') ? PluginManager::isEnabled('ailocal') : true;
$cloudEnabled = class_exists('PluginManager') ? PluginManager::isEnabled('aicloud') : true;
$configured = array_values(array_filter($providers, fn($p) => $p['configured'] && ((!empty($p['local']) && $localEnabled) || (empty($p['local']) && $cloudEnabled))));
$titles = array_column($providers, 'title', 'id');
$dur = fn($ms) => $ms === null ? '-' : sprintf('%d:%02d', intdiv((int) round($ms / 1000), 60), (int) round($ms / 1000) % 60);
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-comment-dots', t('ai_tts.title'), 'ttsHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('ttsHelpBox', t('ai_tts.help_title')); ?>
        <?php echo t('ai_tts.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

    <?php if (!$configured): ?>
        <div class="u-muted u-text-center u-p-24">
            <i class="fas fa-key u-fs-28 u-mb-8" style="display: block;"></i>
            <?php echo t('ai_tts.no_provider'); ?>
            <?php if (hasModulePermission('ai_local', 'view')): ?> <a href="/ai-local"><?php echo t('ai_local.title', 'Local AI'); ?></a><?php endif; ?>
            <?php if (hasModulePermission('ai_cloud', 'view')): ?> · <a href="/ai-cloud"><?php echo t('ai_cloud.title', 'Cloud AI'); ?></a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="u-gtc-minmax-0-1fr-minmax-0-2fr u-gap-16" id="ttsForm" style="display: grid;">
            <div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_tts.field_provider'); ?></label>
                    <select id="ttsProvider" class="form-control">
                        <?php if ($localEnabled && class_exists('AiBindingService') && AiBindingService::resolve('panel_default', 'tts', getUserLanguage(), 'local') !== null): ?>
                            <option value="local_default" data-max="1000"><?php echo $h(t('ai_selector.local_default', 'Local default')); ?></option>
                        <?php endif; ?>
                        <?php if ($cloudEnabled && class_exists('AiBindingService') && AiBindingService::resolve('panel_default', 'tts', getUserLanguage(), 'cloud') !== null): ?>
                            <option value="cloud_default" data-max="4000"><?php echo $h(t('ai_selector.cloud_default', 'Cloud default')); ?></option>
                        <?php endif; ?>
                        <?php foreach ($configured as $p): ?>
                            <option value="<?php echo $h($p['id']); ?>" data-max="<?php echo (int) $p['max_chars']; ?>"><?php echo $h($p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_tts.field_language'); ?></label>
                    <select id="ttsLanguage" class="form-control"></select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_tts.field_voice'); ?>
                        <a href="#" onclick="ttsLoadVoices(true); return false;" class="u-fs-11 u-ml-6" title="<?php echo $h(t('ai_tts.refresh_voices')); ?>"><i class="fas fa-rotate"></i></a></label>
                    <select id="ttsVoice" class="form-control"></select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_tts.field_speed'); ?>: <span id="ttsSpeedVal">1.00</span>×</label>
                    <input type="range" id="ttsSpeed" min="0.5" max="2" step="0.05" value="1" class="u-w-100" oninput="document.getElementById('ttsSpeedVal').textContent = Number(this.value).toFixed(2)">
                </div>
            </div>
            <div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_tts.field_text'); ?></label>
                    <textarea id="ttsText" class="form-control" rows="9" maxlength="<?php echo (int) $max_text; ?>" placeholder="<?php echo $h(t('ai_tts.text_placeholder')); ?>"></textarea>
                    <div class="u-flex-between u-fs-11 u-muted u-mt-4"><span id="ttsCount">0</span><span id="ttsChunks"></span></div>
                </div>
                <?php if ($can_edit): ?>
                    <button type="button" id="ttsGo" class="btn btn-primary" onclick="ttsSynthesize()"><i class="fas fa-wand-magic-sparkles"></i> <?php echo t('ai_tts.btn_speak'); ?></button>
                <?php endif; ?>
                <div id="ttsStatus" class="u-fs-12 u-mt-10"></div>
            </div>
        </div>

        <div id="ttsResult" class="card u-mt-16 u-p-16 u-bg-bg-input" style="display: none;">
            <div class="u-strong u-mb-10"><i class="fas fa-circle-check u-success"></i> <span id="ttsResultTitle"></span></div>
            <audio id="ttsAudio" controls class="u-w-100"></audio>
            <div class="u-flex-gap u-mt-10 u-wrap u-ai-flex-end">
                <a id="ttsDownload" href="#" class="btn btn-secondary btn-sm" data-no-spa="true" download><i class="fas fa-download"></i> MP3</a>
                <?php if ($can_edit): ?>
                    <div class="form-group u-m-0"><label class="form-label u-fs-11"><?php echo t('ai_tts.field_sound_name'); ?></label><input type="text" id="ttsSoundName" class="form-control form-control-sm u-w-180" pattern="[A-Za-z0-9_-]+"></div>
                    <div class="form-group u-m-0"><label class="form-label u-fs-11"><?php echo t('ai_tts.field_sound_title'); ?></label><input type="text" id="ttsSoundTitle" class="form-control form-control-sm u-w-240"></div>
                    <button type="button" class="btn btn-primary btn-sm" onclick="ttsSaveAnnouncement()"><i class="fas fa-file-audio"></i> <?php echo t('ai_tts.btn_save_announcement'); ?></button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="u-strong u-m-24-0-8"><i class="fas fa-clock-rotate-left"></i> <?php echo t('ai_tts.history'); ?></div>
    <?php echo uiTableOpen([
        t('ai_tts.col_date'),
        t('ai_tts.field_provider'),
        t('ai_tts.field_voice'),
        t('ai_tts.field_text'),
        t('ai_tts.col_chars'),
        t('ai_tts.col_duration'),
        t('ai_tts.col_announcement'),
        ['label' => '', 'class' => 'u-text-right'],
    ]); ?>
            <?php if (!$history): ?>
                <tr><td colspan="8" class="u-muted u-text-center u-p-24"><?php echo t('ai_tts.no_history'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($history as $r): ?>
                <tr>
                    <td class="u-nowrap"><?php echo date('d.m.Y H:i', strtotime($r['created_at'])); ?><div class="u-muted u-fs-11"><?php echo $h($r['created_by_name'] ?? ''); ?></div></td>
                    <td><?php echo $h($titles[$r['provider']] ?? $r['provider']); ?></td>
                    <td class="u-fs-12"><?php echo $h($r['voice']); ?><div class="u-muted u-fs-11"><?php echo $h($r['language']); ?></div></td>
                    <td class="u-fs-12 u-maxw-360" title="<?php echo $h($r['text']); ?>"><?php echo $h(mb_strlen($r['text']) > 120 ? mb_substr($r['text'], 0, 120) . '…' : $r['text']); ?></td>
                    <td><?php echo number_format((int) $r['chars'], 0, ',', '.'); ?></td>
                    <td><?php echo $dur($r['duration_ms'] !== null ? (int) $r['duration_ms'] : null); ?></td>
                    <td class="u-fs-12"><?php echo $r['announcement'] ? '<i class="fas fa-check u-success"></i> ' . $h($r['announcement']) : '-'; ?></td>
                    <td class="u-text-right u-nowrap">
                        <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="ttsShow(<?php echo (int) $r['id']; ?>, <?php echo $h(json_encode(mb_substr($r['text'], 0, 60))); ?>)" title="<?php echo $h(t('ai_tts.play')); ?>"><i class="fas fa-play"></i></button>
                        <a href="/api/ai_tts.php?action=audio&id=<?php echo (int) $r['id']; ?>&download=1" class="btn btn-icon btn-secondary btn-sm" data-no-spa="true" download title="MP3"><i class="fas fa-download"></i></a>
                        <?php if ($can_delete): ?>
                            <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(t('ai_tts.confirm_delete'))); ?>)">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                <input type="hidden" name="delete_tts_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" class="btn btn-icon btn-danger btn-sm"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
</div>

<script>
window.TTS = {
    csrf: <?php echo json_encode($csrf_token); ?>,
    lang: <?php echo json_encode(getUserLanguage()); ?>,
    text: <?php echo json_encode([
        'all_voices' => t('ai_tts.multilingual'),
        'loading' => t('ai_tts.loading_voices'),
        'working' => t('ai_tts.working'),
        'chars' => t('ai_tts.chars_count'),
        'chunks' => t('ai_tts.chunks'),
        'test_ok' => t('ai_tts.test_ok'),
        'saved' => t('ai_tts.msg_saved_announcement'),
        'result' => t('ai_tts.result_title'),
        'error' => t('ai_tts.err_generic'),
    ]); ?>
};
</script>
<script src="<?php echo asset('/assets/js/ai_tts.js'); ?>"></script>
