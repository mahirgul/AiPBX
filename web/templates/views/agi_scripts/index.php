<?php
/**
 * PBX -> AGI Scripts (/agi-scripts)
 *
 * @var list<array<string, mixed>> $scripts
 * @var list<string> $agi_bin
 * @var array<string, string> $templates
 */
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$csrf = getCSRFToken();
$langLabels = ['python' => 'Python 3', 'php' => 'PHP', 'bash' => 'Bash'];
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-terminal', t('agi_scripts.title'), 'agiScriptsHelpBox'); ?>
    <span class="badge badge-secondary"><?php echo count($scripts); ?></span>
    <?php echo uiPageHeaderActions(); ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="agiOpen(null)">
            <i class="fas fa-plus-circle"></i> <?php echo $h(t('common.add_new')); ?>
        </button>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('agiScriptsHelpBox', t('agi_scripts.help_title')); ?>
        <p class="u-m-0 u-mb-8"><?php echo $h(t('agi_scripts.help_body')); ?></p>
        <p class="u-m-0"><?php echo $h(t('agi_scripts.help_sandbox')); ?></p>
    <?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        t('common.name'),
        t('agi_scripts.col_language'),
        t('agi_scripts.col_limits'),
        t('agi_scripts.col_used_by'),
        t('common.status'),
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ]); ?>
        <?php if (empty($scripts)): ?>
            <?php echo uiTableEmptyRow(6, t('agi_scripts.empty'), 'fa-terminal'); ?>
        <?php else: ?>
            <?php foreach ($scripts as $s): ?>
                <tr>
                    <td>
                        <code><?php echo $h($s['name']); ?></code>
                        <?php if ($s['description'] !== ''): ?><div class="u-muted u-fs-11"><?php echo $h($s['description']); ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge badge-info"><?php echo $h($langLabels[$s['language']] ?? $s['language']); ?></span></td>
                    <td class="u-fs-12">
                        <?php echo (int) $s['timeout_sec']; ?> s · <?php echo (int) $s['memory_mb']; ?> MB
                        <?php if (!empty($s['network_access'])): ?>
                            <span class="badge badge-warning"><i class="fas fa-globe"></i> <?php echo $h(t('agi_scripts.network')); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="u-fs-12"><?php echo $s['used_by'] ? $h(implode(', ', $s['used_by'])) : '<span class="u-muted">—</span>'; ?></td>
                    <td><?php echo uiStatusToggleForm($s['id'], $s['is_active'], 'id'); ?></td>
                    <td class="text-right">
                        <?php echo uiRowActions($s, 'agiOpen', 'id', 'delete_script', t('agi_scripts.confirm_delete')); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php echo uiTableClose(); ?>

    <?php if ($agi_bin): ?>
        <div class="u-p-12 u-fs-12">
            <strong><?php echo $h(t('agi_scripts.agi_bin')); ?>:</strong>
            <?php foreach ($agi_bin as $f): ?><code><?php echo $h($f); ?></code> <?php endforeach; ?>
            <div class="u-hint"><?php echo $h(t('agi_scripts.agi_bin_hint')); ?></div>
        </div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="agiModal">
    <div class="modal-card u-maxw-960">
        <div class="modal-header">
            <h3 class="u-title" id="agiModalTitle"><i class="fas fa-terminal u-primary"></i> <?php echo $h(t('agi_scripts.new_title')); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="agiClose()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off" id="agiForm">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="save_script" value="1">
                <input type="hidden" name="id" id="agi_id" value="">
                <div class="u-d-grid u-gtc-repeat-4-1fr u-gap-12">
                    <div class="form-group">
                        <label class="form-label" for="agi_name"><?php echo $h(t('common.name')); ?> *</label>
                        <input type="text" name="name" id="agi_name" class="form-control u-mono" required maxlength="64" pattern="[a-z0-9][a-z0-9_\-]*">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="agi_language"><?php echo $h(t('agi_scripts.col_language')); ?></label>
                        <select name="language" id="agi_language" class="form-control">
                            <?php foreach ($langLabels as $k => $label): ?>
                                <option value="<?php echo $h($k); ?>"><?php echo $h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="agi_timeout"><?php echo $h(t('agi_scripts.timeout')); ?></label>
                        <input type="number" name="timeout_sec" id="agi_timeout" class="form-control" min="1" max="60">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="agi_memory"><?php echo $h(t('agi_scripts.memory')); ?></label>
                        <input type="number" name="memory_mb" id="agi_memory" class="form-control" min="32" max="512">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="agi_description"><?php echo $h(t('common.description')); ?></label>
                    <input type="text" name="description" id="agi_description" class="form-control" maxlength="255">
                </div>
                <div class="form-group">
                    <div class="u-d-flex u-jc-space-between u-ai-center u-mb-4">
                        <label class="form-label u-m-0" for="agi_content"><?php echo $h(t('agi_scripts.code')); ?></label>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="agiLoadTemplate()"><i class="fas fa-file-code"></i> <?php echo $h(t('agi_scripts.load_template')); ?></button>
                    </div>
                    <textarea name="content" id="agi_content" class="form-control u-mono u-fs-12" rows="18" spellcheck="false" required></textarea>
                </div>
                <div class="form-group">
                    <label class="u-check-label">
                        <input type="checkbox" name="network_access" id="agi_network" value="1" class="u-check">
                        <span><?php echo $h(t('agi_scripts.network_label')); ?></span>
                    </label>
                    <small class="u-hint"><?php echo $h(t('agi_scripts.network_hint')); ?></small>
                </div>
                <div class="form-group">
                    <label class="u-check-label">
                        <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" id="agi_active" value="1" class="u-check" checked>
                        <span><?php echo $h(t('common.active')); ?></span>
                    </label>
                </div>

                <div class="u-bg-bg-body u-p-12 u-mt-12" id="agiSavedTools">
                    <div class="u-fw-700 u-mb-8"><i class="fas fa-vial u-primary"></i> <?php echo $h(t('agi_scripts.test')); ?></div>
                    <p class="u-hint u-m-0 u-mb-8"><?php echo $h(t('agi_scripts.test_hint')); ?></p>
                    <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-150-1fr u-gap-8">
                        <input type="text" class="form-control" data-agi-sample="caller_id" placeholder="caller_id">
                        <input type="text" class="form-control" data-agi-sample="agi_arg_1" placeholder="agi_arg_1">
                        <input type="text" class="form-control" data-agi-sample="DR_INPUT" placeholder="DR_INPUT">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="agiTest()"><i class="fas fa-play"></i> <?php echo $h(t('agi_scripts.test_run')); ?></button>
                    </div>
                    <pre class="u-mono u-fs-11 u-mt-8 u-m-0" id="agiTestOut" hidden></pre>

                    <div class="u-fw-700 u-mt-12 u-mb-8"><i class="fas fa-clock-rotate-left u-primary"></i> <?php echo $h(t('agi_scripts.versions')); ?></div>
                    <div id="agiVersions" class="u-fs-12"></div>
                </div>

                <?php echo uiModalFooter('agiClose()'); ?>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="agiRestoreForm" hidden>
    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
    <input type="hidden" name="restore_version" value="1">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="version_id" value="">
</form>

<script>window.AGI_TEMPLATES = <?php echo json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo asset('/assets/js/agi_scripts.js'); ?>"></script>
