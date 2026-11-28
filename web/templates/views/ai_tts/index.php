<?php
/** @var string $active_tab @var array $providers @var array $history @var string $csrf_token @var bool $can_edit @var bool $can_delete @var int $max_text */
$moh_classes ??= [];
$prompt_templates ??= [];
$music_presets ??= [];
$music_models ??= ['music_v1', 'music_v2', 'music_v2_5'];
$max_music_prompt ??= 2000;
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$localEnabled = class_exists('PluginManager') ? PluginManager::isEnabled('ailocal') : true;
$cloudEnabled = class_exists('PluginManager') ? PluginManager::isEnabled('aicloud') : true;
$configured = array_values(array_filter($providers, fn($p) => $p['configured'] && ((!empty($p['local']) && $localEnabled) || (empty($p['local']) && $cloudEnabled))));
$musicProviders = array_values(array_filter($configured, fn($p) => !empty($p['supports_music'])));
$titles = array_column($providers, 'title', 'id');
$dur = fn($ms) => $ms === null ? '-' : sprintf('%d:%02d', intdiv((int) round($ms / 1000), 60), (int) round($ms / 1000) % 60);
$tabs = [
    'tts'     => ['label' => t('ai_tts.tab_tts', 'Speech & Prompts'), 'icon' => 'fa-wand-magic-sparkles'],
    'music'   => ['label' => t('ai_tts.tab_music', 'Hold Music (MOH)'), 'icon' => 'fa-music'],
    'history' => ['label' => t('ai_tts.history', 'History'), 'icon' => 'fa-clock-rotate-left', 'badge' => (string) count($history)],
];
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-comment-dots', t('ai_tts.title'), 'ttsHelpBox'); ?>
    <?php echo uiPageHeaderActions(); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiTabs($tabs, $active_tab); ?>
    <?php echo uiHelpBoxOpen('ttsHelpBox', t('ai_tts.help_title')); ?>
        <?php echo t('ai_tts.help_body'); ?>
    <?php echo uiHelpBoxClose(); ?>

    <div data-tab-pane="tts"<?php echo $active_tab === 'tts' ? '' : ' hidden'; ?>>
        <?php if (!$configured): ?>
            <div class="u-muted u-text-center u-p-24">
                <i class="fas fa-key u-fs-28 u-mb-8 d-block"></i>
                <?php echo t('ai_tts.no_provider'); ?>
                <?php if (hasModulePermission('ai_local', 'view')): ?> <a href="/ai-local"><?php echo t('ai_local.title', 'Local AI'); ?></a><?php endif; ?>
                <?php if (hasModulePermission('ai_cloud', 'view')): ?> · <a href="/ai-cloud"><?php echo t('ai_cloud.title', 'Cloud AI'); ?></a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="u-d-grid u-gtc-minmax-0-1fr-minmax-0-2fr u-gap-16" id="ttsForm">
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
                            <a href="#" onclick="ttsLoadVoices(true); return false;" class="u-fs-11 u-ml-6" title="<?php echo $h(t('ai_tts.refresh_voices')); ?>"><i class="fas fa-rotate"></i></a></label>
                        <select id="ttsVoice" class="form-control"></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_tts.field_speed'); ?>: <span id="ttsSpeedVal">1.00</span>×</label>
                        <input type="range" id="ttsSpeed" min="0.5" max="2" step="0.05" value="1" class="u-w-100" oninput="document.getElementById('ttsSpeedVal').textContent = Number(this.value).toFixed(2)">
                    </div>
                </div>
                <div>
                    <?php if ($prompt_templates): ?>
                        <div class="form-group">
                            <label class="form-label" for="ttsTemplate"><?php echo t('ai_tts.field_prompt_template'); ?></label>
                            <select id="ttsTemplate" class="form-control">
                                <option value=""><?php echo t('ai_tts.template_custom'); ?></option>
                                <?php foreach ($prompt_templates as $tpl): ?>
                                    <option value="<?php echo $h($tpl['id']); ?>"><?php echo $h($tpl['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="u-hint"><?php echo t('ai_tts.template_help'); ?></small>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_tts.field_text'); ?></label>
                        <textarea id="ttsText" class="form-control" rows="8" maxlength="<?php echo (int) $max_text; ?>" placeholder="<?php echo $h(t('ai_tts.text_placeholder')); ?>"></textarea>
                        <div class="u-flex-between u-fs-11 u-muted u-mt-4"><span id="ttsCount">0</span><span id="ttsChunks"></span></div>
                    </div>
                    <?php if ($can_edit): ?>
                        <button type="button" id="ttsGo" class="btn btn-primary" onclick="ttsSynthesize()"><i class="fas fa-wand-magic-sparkles"></i> <?php echo t('ai_tts.btn_speak'); ?></button>
                    <?php endif; ?>
                    <div id="ttsStatus" class="u-fs-12 u-mt-10"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div data-tab-pane="music"<?php echo $active_tab === 'music' ? '' : ' hidden'; ?>>
        <?php if (!$musicProviders): ?>
            <div class="u-muted u-text-center u-p-24">
                <i class="fas fa-music u-fs-28 u-mb-8 d-block"></i>
                <p class="u-mb-8"><?php echo t('ai_tts.music_no_elevenlabs'); ?></p>
                <?php if (hasModulePermission('ai_cloud', 'view')): ?>
                    <a href="/ai-cloud" class="btn btn-primary btn-sm"><i class="fas fa-plug"></i> <?php echo t('ai_cloud.title', 'Cloud AI'); ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="u-d-grid u-gtc-minmax-0-1fr-minmax-0-2fr u-gap-16" id="musicForm">
                <div>
                    <div class="form-group">
                        <label class="form-label" for="musicProvider"><?php echo t('ai_tts.field_provider'); ?></label>
                        <select id="musicProvider" class="form-control">
                            <?php foreach ($musicProviders as $mp): ?>
                                <option value="<?php echo $h($mp['id']); ?>"><?php echo $h($mp['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($music_presets): ?>
                        <div class="form-group">
                            <label class="form-label" for="musicPreset"><?php echo t('ai_tts.field_music_preset'); ?></label>
                            <select id="musicPreset" class="form-control">
                                <option value=""><?php echo t('ai_tts.music_preset_custom'); ?></option>
                                <?php foreach ($music_presets as $pr): ?>
                                    <option value="<?php echo $h($pr['id']); ?>"><?php echo $h($pr['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label" for="musicDuration"><?php echo t('ai_tts.field_music_duration'); ?></label>
                        <select id="musicDuration" class="form-control">
                            <option value="30">30 s</option>
                            <option value="60" selected>60 s</option>
                            <option value="120">120 s</option>
                            <option value="180">180 s</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="musicModel"><?php echo t('ai_tts.field_music_model'); ?></label>
                        <select id="musicModel" class="form-control">
                            <?php foreach ($music_models as $mm): ?>
                                <option value="<?php echo $h($mm); ?>"><?php echo $h($mm); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="u-check-label">
                            <input type="checkbox" id="musicInstrumental" class="u-check" checked>
                            <span><?php echo t('ai_tts.field_instrumental'); ?></span>
                        </label>
                    </div>
                </div>
                <div>
                    <div class="form-group">
                        <label class="form-label" for="musicPrompt"><?php echo t('ai_tts.field_music_prompt'); ?></label>
                        <textarea id="musicPrompt" class="form-control" rows="8" maxlength="<?php echo (int) $max_music_prompt; ?>" placeholder="<?php echo $h(t('ai_tts.music_prompt_placeholder')); ?>"></textarea>
                        <div class="u-flex-between u-fs-11 u-muted u-mt-4"><span id="musicCount">0</span><span><?php echo t('ai_tts.music_hint'); ?></span></div>
                    </div>
                    <?php if ($can_edit): ?>
                        <button type="button" id="musicGo" class="btn btn-primary" onclick="ttsGenerateMusic()"><i class="fas fa-music"></i> <?php echo t('ai_tts.btn_generate_music'); ?></button>
                    <?php endif; ?>
                    <div id="musicStatus" class="u-fs-12 u-mt-10"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div data-tab-pane="history"<?php echo $active_tab === 'history' ? '' : ' hidden'; ?>>
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
                <?php echo uiTableEmptyRow(8, t('ai_tts.no_history'), 'fa-clock-rotate-left'); ?>
            <?php else: ?>
                <?php foreach ($history as $r):
                    $isMusicRow = str_starts_with((string) $r['voice'], 'music:');
                ?>
                    <tr>
                        <td class="u-nowrap"><?php echo date('d.m.Y H:i', strtotime($r['created_at'])); ?><div class="u-muted u-fs-11"><?php echo $h($r['created_by_name'] ?? ''); ?></div></td>
                        <td><?php echo $h($titles[$r['provider']] ?? $r['provider']); ?></td>
                        <td class="u-fs-12">
                            <i class="fas <?php echo $isMusicRow ? 'fa-music u-primary' : 'fa-microphone u-muted'; ?>"></i>
                            <?php echo $h($r['voice']); ?>
                            <div class="u-muted u-fs-11"><?php echo $h($r['language']); ?></div>
                        </td>
                        <td class="u-fs-12 u-maxw-360" title="<?php echo $h($r['text']); ?>"><?php echo $h(mb_strlen($r['text']) > 120 ? mb_substr($r['text'], 0, 120) . '…' : $r['text']); ?></td>
                        <td><?php echo number_format((int) $r['chars'], 0, ',', '.'); ?></td>
                        <td><?php echo $dur($r['duration_ms'] !== null ? (int) $r['duration_ms'] : null); ?></td>
                        <td class="u-fs-12"><?php echo $r['announcement'] ? '<i class="fas fa-check u-success"></i> ' . $h($r['announcement']) : '-'; ?></td>
                        <td class="u-text-right u-nowrap">
                            <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="ttsShow(<?php echo (int) $r['id']; ?>, <?php echo $h(json_encode(mb_substr($r['text'], 0, 60))); ?>, { isMusic: <?php echo $isMusicRow ? 'true' : 'false'; ?> })" title="<?php echo $h(t('ai_tts.play')); ?>"><i class="fas fa-play"></i></button>
                            <a href="/api/ai_tts.php?action=audio&id=<?php echo (int) $r['id']; ?>&download=1" class="btn btn-icon btn-secondary btn-sm" data-no-spa="true" download title="MP3"><i class="fas fa-download"></i></a>
                            <?php if ($can_delete): ?>
                                <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(t('ai_tts.confirm_delete'))); ?>)">
                                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                    <input type="hidden" name="_tab" value="history">
                                    <input type="hidden" name="delete_tts_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" class="btn btn-icon btn-danger btn-sm"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php echo uiTableClose(); ?>
    </div>

    <div id="ttsResult" class="card u-mt-16 u-p-16 u-bg-bg-input d-none" hidden>
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
        <?php if ($can_edit): ?>
            <div class="u-flex-gap u-mt-10 u-wrap u-ai-flex-end">
                <div class="form-group u-m-0">
                    <label class="form-label u-fs-11" for="ttsMohClass"><?php echo t('ai_tts.field_moh_class'); ?></label>
                    <select id="ttsMohClass" class="form-control form-control-sm u-w-180">
                        <?php foreach ($moh_classes as $mc): ?>
                            <option value="<?php echo $h($mc['name']); ?>"><?php echo $h($mc['name']); ?></option>
                        <?php endforeach; ?>
                        <?php if (!$moh_classes): ?>
                            <option value="default">default</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group u-m-0">
                    <label class="form-label u-fs-11" for="ttsMohFileName"><?php echo t('ai_tts.field_moh_file_name'); ?></label>
                    <input type="text" id="ttsMohFileName" class="form-control form-control-sm u-w-180" pattern="[A-Za-z0-9_-]+">
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="ttsSaveMoh()"><i class="fas fa-music"></i> <?php echo t('ai_tts.btn_save_moh'); ?></button>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
window.TTS = {
    csrf: <?php echo json_encode($csrf_token); ?>,
    lang: <?php echo json_encode(getUserLanguage()); ?>,
    templates: <?php echo json_encode($prompt_templates, JSON_UNESCAPED_UNICODE); ?>,
    musicPresets: <?php echo json_encode($music_presets, JSON_UNESCAPED_UNICODE); ?>,
    text: <?php echo json_encode([
        'all_voices' => t('ai_tts.multilingual'),
        'loading' => t('ai_tts.loading_voices'),
        'working' => t('ai_tts.working'),
        'music_working' => t('ai_tts.music_working'),
        'chars' => t('ai_tts.chars_count'),
        'chunks' => t('ai_tts.chunks'),
        'test_ok' => t('ai_tts.test_ok'),
        'saved' => t('ai_tts.msg_saved_announcement'),
        'saved_moh' => t('ai_tts.msg_saved_moh'),
        'result' => t('ai_tts.result_title'),
        'error' => t('ai_tts.err_generic'),
    ]); ?>
};
</script>
<script src="<?php echo asset('/assets/js/ai_tts.js'); ?>"></script>
