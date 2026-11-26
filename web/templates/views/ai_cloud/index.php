<?php
/** @var bool $plugin_enabled @var bool $privacy_blocked  @var array $providers @var array $types @var array $dependencies @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$realtimeTypes = array_keys(CloudAiService::REALTIME_MODELS);
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-cloud', t('ai_cloud.title', 'Cloud AI'), 'aiCloudHelpBox'); ?>
    <?php echo uiPageHeaderActions(); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('aiCloudHelpBox', t('ai_cloud.title', 'Cloud AI')); ?>
        <?php echo $h(t('ai_cloud.help_body', 'One account is one provider with one set of settings. Every AI feature picks its own account.')); ?>
    <?php echo uiHelpBoxClose(); ?>

<?php if (!$plugin_enabled): ?>
    <div class="alert alert-warning u-mb-12">
        <i class="fas fa-plug-circle-xmark"></i>
        <?php echo $h(t('ai_cloud.plugin_disabled', 'Cloud AI plugin (aicloud) is currently disabled. Enable it from Plugins to use cloud providers.')); ?>
    </div>
<?php endif; ?>

<?php if ($privacy_blocked): ?>
    <div class="alert alert-danger u-mb-12">
        <i class="fas fa-shield-halved"></i>
        <?php echo $h(t('ai_gateway.error_privacy', 'Cloud AI is blocked by global privacy settings.')); ?>
    </div>
<?php endif; ?>

<?php $modeLabel = static fn (string $type): string => in_array($type, $realtimeTypes, true) ? ' [Cascade + Realtime]' : ' [Cascade]'; ?>
<div>
    <?php
    $capLabels = ['llm' => 'LLM', 'stt' => 'STT', 'tts' => 'TTS', 'embed' => 'Embed'];
    $fmtNum = fn($n) => number_format((float) $n, 0, ',', '.');
    ?>
        <div class="u-d-flex u-jc-space-between u-ai-center u-wrap u-gap-8 u-mb-10">
            <h3 class="card-title u-m-0"><i class="fas fa-cloud u-primary"></i> <?php echo $h(t('ai_cloud.accounts_title')); ?></h3>
            <button type="button" class="btn btn-primary btn-sm" onclick="aiCloudOpenAccount('')"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-plus"></i> <?php echo $h(t('ai_cloud.account_add')); ?></button>
        </div>
        <?php if ($providers === []): ?>
            <p class="u-muted u-fs-13 u-m-0"><?php echo $h(t('ai_cloud.accounts_empty')); ?></p>
        <?php else: ?>
        <?php echo uiTableOpen([
            ['label' => t('ai_cloud.account_name')],
            ['label' => t('ai_cloud.provider', 'Provider'), 'class' => 'col-hide-mobile'],
            ['label' => t('ai_cloud.account_caps'), 'class' => 'col-hide-mobile'],
            ['label' => t('ai_cloud.account_usage'), 'class' => 'col-hide-mobile'],
            ['label' => t('common.status', 'Status')],
            ['label' => '', 'class' => 'u-text-right'],
        ], ['search' => false]); ?>
            <?php foreach ($providers as $p):
                $u = $p['usage'] ?? null;
                $usageParts = [];
                if ($u) {
                    if ((float) $u['stt_seconds'] > 0) { $usageParts[] = $fmtNum((float) $u['stt_seconds'] / 60) . ' min'; }
                    if ((int) $u['tts_chars'] > 0) { $usageParts[] = $fmtNum($u['tts_chars']) . ' chars'; }
                    if ((int) $u['llm_tokens'] > 0) { $usageParts[] = $fmtNum($u['llm_tokens']) . ' tokens'; }
                }
            ?>
                <tr>
                    <td><strong><?php echo $h($p['title']); ?></strong> <span class="u-muted u-fs-11 u-mono"><?php echo $h($p['id']); ?></span></td>
                    <td class="col-hide-mobile"><?php echo $h($p['provider_title']); ?></td>
                    <td class="col-hide-mobile">
                        <?php foreach ((array) $p['caps'] as $c): ?><span class="badge badge-secondary u-fs-11"><?php echo $h($capLabels[$c] ?? $c); ?></span> <?php endforeach; ?>
                        <?php if (in_array((string) ($p['type'] ?? $p['id']), $realtimeTypes, true)): ?>
                            <span class="badge badge-primary u-fs-11">Realtime</span>
                        <?php endif; ?>
                        <span class="badge badge-info u-fs-11">Cascade</span>
                    </td>
                    <td class="col-hide-mobile u-fs-12 u-muted"><?php echo $h($usageParts ? implode(' · ', $usageParts) : '—'); ?></td>
                    <td>
                        <?php if (empty($p['enabled'])): ?>
                            <span class="badge badge-secondary"><?php echo $h(t('ai_cloud.account_off')); ?></span>
                        <?php elseif (empty($p['complete'])): ?>
                            <span class="badge badge-warning"><?php echo $h(t('common.not_configured', 'Not configured')); ?></span>
                        <?php else: ?>
                            <span class="badge badge-success"><?php echo $h(t('ai_cloud.account_on')); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="u-text-right u-nowrap">
                        <button type="button" class="btn btn-icon btn-secondary btn-xs" onclick="aiCloudOpenAccount(<?php echo $h(json_encode($p['id'])); ?>)" title="<?php echo $h(t('common.edit', 'Edit')); ?>"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-pen"></i></button>
                        <form method="POST" class="u-inline">
                            <?php echo $csrf; ?>
                            <button type="submit" name="copy_account" value="<?php echo $h($p['id']); ?>" class="btn btn-icon btn-secondary btn-xs" title="<?php echo $h(t('ai_cloud.account_copy')); ?>"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-copy"></i></button>
                        </form>
                        <form method="POST" class="u-inline">
                            <?php echo $csrf; ?>
                                                        <input type="hidden" name="enable" value="<?php echo empty($p['enabled']) ? '1' : '0'; ?>">
                            <button type="submit" name="toggle_account" value="<?php echo $h($p['id']); ?>" class="btn btn-icon btn-secondary btn-xs" title="<?php echo $h(t(empty($p['enabled']) ? 'ai_cloud.account_enable' : 'ai_cloud.account_disable')); ?>"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas <?php echo empty($p['enabled']) ? 'fa-toggle-off' : 'fa-toggle-on u-success'; ?>"></i></button>
                        </form>
                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(sprintf(t('ai_cloud.account_confirm_delete'), $p['title']))); ?>)">
                            <?php echo $csrf; ?>
                                                        <button type="submit" name="delete_account" value="<?php echo $h($p['id']); ?>" class="btn btn-icon btn-danger btn-xs" title="<?php echo $h(t('common.delete', 'Delete')); ?>"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-trash-alt"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
        <?php endif; ?>
</div>

<?php if (!empty($dependencies)): ?>
    <div class="card u-mt-12">
        <div class="u-fs-12 u-fw-700 u-mb-6"><i class="fas fa-link u-primary"></i> <?php echo $h(t('ai_cloud.active_deps', 'Active cloud consumers')); ?></div>
        <div class="u-d-flex u-wrap u-gap-6">
            <?php foreach ($dependencies as $dep): ?>
                <span class="badge badge-info u-fs-11"><?php echo $h($dep['label'] . ' → ' . $dep['target']); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Add / edit one account -->
<div class="modal-overlay" id="aiCloudAccountModal">
    <div class="modal-card u-maxw-800">
        <div class="modal-header">
            <h3 class="u-title" id="aiCloudAccountTitle"></h3>
            <button type="button" class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('aiCloudAccountModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" autocomplete="off" id="aiCloudAccountForm">
            <div class="modal-body">
                <?php echo $csrf; ?>
                                <input type="hidden" name="save_account" value="1">
                <input type="hidden" name="account_id" id="aiCloudAccountId" value="">
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo $h(t('ai_cloud.provider', 'Provider')); ?></label>
                        <select name="account_type" id="aiCloudAccountType" class="form-control" onchange="aiCloudRenderFields()">
                            <?php foreach ($types as $tp): ?>
                                <option value="<?php echo $h($tp['type']); ?>"><?php echo $h($tp['title'] . $modeLabel((string) $tp['type'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="u-muted u-fs-11" id="aiCloudAccountSignup"></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo $h(t('ai_cloud.account_name')); ?></label>
                        <input type="text" name="account_name" id="aiCloudAccountName" class="form-control" maxlength="60" placeholder="<?php echo $h(t('ai_cloud.account_name_hint')); ?>">
                    </div>
                </div>
                <div id="aiCloudAccountFields" class="u-grid-2"></div>
                <details class="u-mt-10">
                    <summary class="u-pointer u-fs-12 u-fw-600"><?php echo $h(t('ai_cloud.account_limits')); ?></summary>
                    <div class="u-d-flex u-wrap u-gap-8 u-mt-8">
                        <label class="u-fs-12">STT (min) <input type="number" min="0" name="limit_stt_min" id="aiCloudLimit_stt_min" class="form-control form-control-sm u-w-120"></label>
                        <label class="u-fs-12">TTS (chars) <input type="number" min="0" name="limit_tts_chars" id="aiCloudLimit_tts_chars" class="form-control form-control-sm u-w-120"></label>
                        <label class="u-fs-12">LLM (tokens) <input type="number" min="0" name="limit_llm_tokens" id="aiCloudLimit_llm_tokens" class="form-control form-control-sm u-w-120"></label>
                    </div>
                    <small class="u-muted u-fs-11"><?php echo $h(t('ai_cloud.account_limits_help')); ?></small>
                </details>
                <div class="u-fs-12 u-mt-10" id="aiCloudTestResult"></div>
            </div>
            <div class="modal-footer u-d-flex u-gap-8 u-jc-flex-end">
                <button type="button" class="btn btn-secondary" onclick="aiCloudTestAccount()"><i class="fas fa-vial"></i> <?php echo $h(t('ai_cloud.account_test')); ?></button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?></button>
            </div>
        </form>
    </div>
</div>
<script>window.AI_CLOUD = <?php echo json_encode(['types' => $types, 'accounts' => array_map(fn($p) => array_intersect_key($p, array_flip(['id', 'type', 'name', 'fields', 'limits'])), $providers)], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;</script>

</div>

<script src="<?php echo asset('/assets/js/ai_cloud.js'); ?>"></script>
