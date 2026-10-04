<?php
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$configured = array_values(array_filter($providers, fn($p) => $p['configured']));
$titles = array_column($providers, 'title', 'id');
$dur = fn($ms) => $ms === null ? '-' : sprintf('%d:%02d', intdiv((int) round($ms / 1000), 60), (int) round($ms / 1000) % 60);
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-comment-dots" style="color: var(--purple);"></i> <?php echo t('ai_tts.title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('ttsHelpBox')" title="<?php echo t('common.module_guide'); ?>"><i class="fas fa-question-circle"></i></button>
    </div>
    <div class="module-help-box" id="ttsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('ai_tts.help_title'); ?></h4>
        <?php echo t('ai_tts.help_body'); ?>
    </div>

    <div style="display: flex; gap: 4px; border-bottom: 1px solid var(--border-color); margin-bottom: 16px;">
        <?php foreach (['speak' => ['fa-microphone-lines', t('ai_tts.tab_speak')], 'providers' => ['fa-key', t('ai_tts.tab_providers')]] as $tk => [$ic, $label]): ?>
            <a href="/ai-tts<?php echo $tk === 'providers' ? '?tab=providers' : ''; ?>" style="padding: 10px 14px; font-size: 13px; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?php echo $tab === $tk ? 'var(--primary)' : 'transparent'; ?>; color: <?php echo $tab === $tk ? 'var(--primary)' : 'var(--text-muted)'; ?>;">
                <i class="fas <?php echo $ic; ?>"></i> <?php echo $label; ?>
            </a>
        <?php endforeach; ?>
    </div>

<?php if ($tab === 'providers'): ?>
    <p class="u-muted u-fs-12" style="margin-top: 0;"><?php echo t('ai_tts.providers_text'); ?></p>
    <div class="u-grid-2" style="gap: 16px;">
    <?php foreach ($providers as $p): ?>
        <form method="POST" autocomplete="off" class="card u-mb-0" style="padding: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="save_provider" value="<?php echo $h($p['id']); ?>">
            <div class="u-flex-between u-mb-10">
                <div class="u-strong"><?php echo $h($p['title']); ?></div>
                <span class="badge <?php echo $p['configured'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11"><?php echo t($p['configured'] ? 'ai_tts.configured' : 'ai_tts.not_configured'); ?></span>
            </div>
            <?php foreach ($p['fields'] as $f): ?>
                <div class="form-group">
                    <label class="form-label"><?php echo $h($f['label']); ?><?php echo !empty($f['optional']) ? ' <span class="u-muted u-fs-11">(' . t('ai_tts.optional') . ')</span>' : ''; ?></label>
                    <?php if ($f['secret']): ?>
                        <input type="password" name="<?php echo $h($f['key']); ?>" class="form-control" autocomplete="new-password"
                               placeholder="<?php echo $h($f['masked'] !== '' ? $f['masked'] . ' — ' . t('ai_tts.keep_secret') : ''); ?>" <?php echo $can_edit ? '' : 'disabled'; ?>>
                    <?php else: ?>
                        <input type="text" name="<?php echo $h($f['key']); ?>" class="form-control" value="<?php echo $h($f['value']); ?>" placeholder="<?php echo $h($f['default'] ?? ''); ?>" <?php echo $can_edit ? '' : 'disabled'; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="u-muted u-fs-11 u-mb-10"><?php echo sprintf(t('ai_tts.provider_hint_' . $p['id']), number_format($p['max_chars'], 0, ',', '.')); ?></div>
            <?php if ($can_edit): ?>
                <div class="u-flex-gap">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
                    <?php if ($p['configured']): ?>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="ttsTest('<?php echo $h($p['id']); ?>', this)"><i class="fas fa-plug-circle-check"></i> <?php echo t('ai_tts.btn_test'); ?></button>
                        <button type="submit" name="clear" value="1" class="btn btn-danger btn-sm" onclick="return confirm(<?php echo $h(json_encode(t('ai_tts.confirm_clear'))); ?>)"><i class="fas fa-trash-alt"></i></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    <?php endforeach; ?>
    </div>

<?php else: ?>
    <?php if (!$configured): ?>
        <div class="u-muted u-text-center u-p-24">
            <i class="fas fa-key" style="font-size: 28px; display: block; margin-bottom: 8px;"></i>
            <?php echo t('ai_tts.no_provider'); ?> <a href="/ai-tts?tab=providers"><?php echo t('ai_tts.tab_providers'); ?></a>
        </div>
    <?php else: ?>
        <div id="ttsForm" style="display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); gap: 16px;">
            <div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_tts.field_provider'); ?></label>
                    <select id="ttsProvider" class="form-control">
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
                        <a href="#" onclick="ttsLoadVoices(true); return false;" class="u-fs-11" style="margin-left: 6px;" title="<?php echo $h(t('ai_tts.refresh_voices')); ?>"><i class="fas fa-rotate"></i></a></label>
                    <select id="ttsVoice" class="form-control"></select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_tts.field_speed'); ?>: <span id="ttsSpeedVal">1.00</span>×</label>
                    <input type="range" id="ttsSpeed" min="0.5" max="2" step="0.05" value="1" style="width: 100%;" oninput="document.getElementById('ttsSpeedVal').textContent = Number(this.value).toFixed(2)">
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

        <div id="ttsResult" class="card" style="display: none; margin-top: 16px; padding: 16px; background: var(--bg-input);">
            <div class="u-strong u-mb-10"><i class="fas fa-circle-check u-success"></i> <span id="ttsResultTitle"></span></div>
            <audio id="ttsAudio" controls style="width: 100%;"></audio>
            <div class="u-flex-gap u-mt-10" style="flex-wrap: wrap; align-items: flex-end;">
                <a id="ttsDownload" href="#" class="btn btn-secondary btn-sm" data-no-spa="true" download><i class="fas fa-download"></i> MP3</a>
                <?php if ($can_edit): ?>
                    <div class="form-group u-m-0"><label class="form-label u-fs-11"><?php echo t('ai_tts.field_sound_name'); ?></label><input type="text" id="ttsSoundName" class="form-control form-control-sm" pattern="[A-Za-z0-9_-]+" style="width: 180px;"></div>
                    <div class="form-group u-m-0"><label class="form-label u-fs-11"><?php echo t('ai_tts.field_sound_title'); ?></label><input type="text" id="ttsSoundTitle" class="form-control form-control-sm" style="width: 240px;"></div>
                    <button type="button" class="btn btn-primary btn-sm" onclick="ttsSaveAnnouncement()"><i class="fas fa-file-audio"></i> <?php echo t('ai_tts.btn_save_announcement'); ?></button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="u-strong" style="margin: 24px 0 8px;"><i class="fas fa-clock-rotate-left"></i> <?php echo t('ai_tts.history'); ?></div>
    <div class="table-responsive">
        <table class="data-table" data-no-dt="true">
            <thead><tr>
                <th><?php echo t('ai_tts.col_date'); ?></th><th><?php echo t('ai_tts.field_provider'); ?></th><th><?php echo t('ai_tts.field_voice'); ?></th>
                <th><?php echo t('ai_tts.field_text'); ?></th><th><?php echo t('ai_tts.col_chars'); ?></th><th><?php echo t('ai_tts.col_duration'); ?></th>
                <th><?php echo t('ai_tts.col_announcement'); ?></th><th class="u-text-right"></th>
            </tr></thead>
            <tbody>
            <?php if (!$history): ?>
                <tr><td colspan="8" class="u-muted u-text-center u-p-24"><?php echo t('ai_tts.no_history'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($history as $r): ?>
                <tr>
                    <td style="white-space: nowrap;"><?php echo date('d.m.Y H:i', strtotime($r['created_at'])); ?><div class="u-muted u-fs-11"><?php echo $h($r['created_by_name'] ?? ''); ?></div></td>
                    <td><?php echo $h($titles[$r['provider']] ?? $r['provider']); ?></td>
                    <td class="u-fs-12"><?php echo $h($r['voice']); ?><div class="u-muted u-fs-11"><?php echo $h($r['language']); ?></div></td>
                    <td class="u-fs-12" style="max-width: 360px;" title="<?php echo $h($r['text']); ?>"><?php echo $h(mb_strlen($r['text']) > 120 ? mb_substr($r['text'], 0, 120) . '…' : $r['text']); ?></td>
                    <td><?php echo number_format((int) $r['chars'], 0, ',', '.'); ?></td>
                    <td><?php echo $dur($r['duration_ms'] !== null ? (int) $r['duration_ms'] : null); ?></td>
                    <td class="u-fs-12"><?php echo $r['announcement'] ? '<i class="fas fa-check u-success"></i> ' . $h($r['announcement']) : '-'; ?></td>
                    <td class="u-text-right" style="white-space: nowrap;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="ttsShow(<?php echo (int) $r['id']; ?>, <?php echo $h(json_encode(mb_substr($r['text'], 0, 60))); ?>)" title="<?php echo $h(t('ai_tts.play')); ?>"><i class="fas fa-play"></i></button>
                        <a href="/api/ai_tts.php?action=audio&id=<?php echo (int) $r['id']; ?>&download=1" class="btn btn-secondary btn-sm" data-no-spa="true" download title="MP3"><i class="fas fa-download"></i></a>
                        <?php if ($can_delete): ?>
                            <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(t('ai_tts.confirm_delete'))); ?>)">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                <input type="hidden" name="delete_tts_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
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
