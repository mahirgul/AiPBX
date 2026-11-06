<?php
/**
 * Admin -> REST API Keys (`/api-keys`)
 *
 * @var list<array<string, mixed>> $tokens
 * @var array<string, string> $available_scopes
 * @var string $created_raw_token
 * @var string $csrf_token
 */
?>

<?php if ($created_raw_token !== ''): ?>
    <div class="card u-mb-20 u-bd-2-solid-success">
        <div class="card-header u-bg-rgba-16-185-129-0-1">
            <div class="card-title u-success">
                <i class="fas fa-key"></i> <?php echo htmlspecialchars(t('api_keys.new_token_title', 'New API Token Generated — Copy Now')); ?>
            </div>
        </div>
        <div class="u-p-20-20">
            <p class="u-m-0-0-12 u-fs-13 u-text-main">
                <?php echo htmlspecialchars(t('api_keys.new_token_warning', 'For security reasons, only the SHA-256 hash of this token is stored in the database. Copy and store this token securely now; it cannot be displayed again.')); ?>
            </p>
            <div class="u-d-flex u-ai-center u-gap-10">
                <input type="text" id="newApiTokenInput" class="form-control u-mono u-fw-700 u-fs-14" value="<?php echo htmlspecialchars($created_raw_token); ?>" readonly>
                <button type="button" class="btn btn-primary" onclick="navigator.clipboard.writeText(document.getElementById('newApiTokenInput').value);">
                    <i class="fas fa-copy"></i> <?php echo htmlspecialchars(t('common.copy', 'Copy')); ?>
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card u-mb-20">
    <?php echo uiPageHeaderOpen('fa-key', htmlspecialchars(t('api_keys.title', 'REST API Keys (/api/v1/*)')), 'apiKeysHelpBox'); ?>
<span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($tokens); ?></span>
<?php echo uiPageHeaderActions(); ?>
<div class="u-flex-center u-gap-8">
            <a href="/api/v1/openapi.json" target="_blank" class="btn btn-secondary btn-sm">
                <i class="fas fa-file-code"></i> OpenAPI 3.1 JSON
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('createTokenBox').style.display = document.getElementById('createTokenBox').style.display === 'none' ? 'block' : 'none';">
                <i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars(t('api_keys.btn_new', 'Generate API Key')); ?>
            </button>
        </div>
<?php echo uiPageHeaderClose(); ?>
<?php echo uiHelpBoxOpen('apiKeysHelpBox', t('common.module_guide')); ?><?php echo t('api_keys.help_body'); ?><?php echo uiHelpBoxClose(); ?>

    <div id="createTokenBox" style="display: none; padding: 20px; border-bottom: 1px solid var(--border-color); background: var(--bg-subtle, rgba(2,132,199,0.04));">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="create_api_token" value="1">

            <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-16 u-mb-16">
                <div class="form-group">
                    <label class="form-label u-fw-600"><?php echo htmlspecialchars(t('api_keys.field_name', 'Token Name / Integration')); ?></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. CRM Integration, WordPress, Billing" required>
                </div>
                <div class="form-group">
                    <label class="form-label u-fw-600"><?php echo htmlspecialchars(t('api_keys.field_ips', 'Allowed IP / CIDR List (Optional)')); ?></label>
                    <input type="text" name="allowed_ips" class="form-control" placeholder="e.g. 127.0.0.1, 10.0.0.0/24 (empty = all)">
                </div>
                <div class="form-group">
                    <label class="form-label u-fw-600"><?php echo htmlspecialchars(t('api_keys.field_rate_limit', 'Rate Limit (req / min)')); ?></label>
                    <input type="number" name="rate_limit_per_min" class="form-control" value="120" min="10" max="10000">
                </div>
                <div class="form-group">
                    <label class="form-label u-fw-600"><?php echo htmlspecialchars(t('api_keys.field_expires', 'Expiration Date (Optional)')); ?></label>
                    <input type="date" name="expires_at" class="form-control">
                </div>
            </div>

            <div class="form-group u-mb-16">
                <label class="form-label u-fw-600 u-mb-8" style="display: block;">
                    <?php echo htmlspecialchars(t('api_keys.field_scopes', 'Permission Scopes')); ?>
                </label>
                <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-300-1fr u-gap-8">
                    <?php foreach ($available_scopes as $scopeKey => $scopeDesc): ?>
                        <label class="u-d-flex u-ai-center u-gap-8 u-p-8-12 u-bd-1-solid-border-color u-r-8 u-pointer u-fs-12">
                            <input type="checkbox" name="scopes[]" value="<?php echo htmlspecialchars($scopeKey); ?>" <?php echo $scopeKey === '*' ? '' : ''; ?>>
                            <span><code><?php echo htmlspecialchars($scopeKey); ?></code> — <?php echo htmlspecialchars($scopeDesc); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="u-d-flex u-jc-flex-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-key"></i> <?php echo htmlspecialchars(t('api_keys.btn_create_submit', 'Create API Token')); ?>
                </button>
            </div>
        </form>
    </div>

    <?php echo uiTableOpen([
        htmlspecialchars(t('api_keys.col_name', 'Name & Prefix')),
        htmlspecialchars(t('api_keys.col_scopes', 'Scopes')),
        htmlspecialchars(t('api_keys.col_restrictions', 'IP & Rate Limit')),
        htmlspecialchars(t('api_keys.col_last_used', 'Last Used')),
        htmlspecialchars(t('common.status', 'Status')),
        ['label' => htmlspecialchars(t('common.actions', 'Actions')), 'class' => 'text-right'],
    ]); ?>
                <?php if (empty($tokens)): ?>
                    <?php echo uiTableEmptyRow(6, t('api_keys.empty', 'No API tokens created yet.'), 'fa-key'); ?>
                <?php else: ?>
                    <?php foreach ($tokens as $tok): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$tok['name']); ?></strong>
                                <?php if (!empty($tok['plugin_slug'])): ?>
                                    <span class="badge badge-info u-fs-11">plugin:<?php echo htmlspecialchars((string)$tok['plugin_slug']); ?></span>
                                <?php endif; ?>
                                <div class="text-muted u-fs-11"><code><?php echo htmlspecialchars((string)$tok['token_prefix']); ?></code></div>
                            </td>
                            <td>
                                <div class="u-d-flex u-wrap u-gap-4">
                                    <?php foreach ($tok['scopes'] as $sc): ?>
                                        <span class="badge <?php echo $sc === '*' ? 'badge-warning' : 'badge-secondary'; ?> u-fs-11"><?php echo htmlspecialchars((string)$sc); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <div class="u-fs-12"><?php echo !empty($tok['allowed_ips']) ? htmlspecialchars((string)$tok['allowed_ips']) : htmlspecialchars(t('api_keys.any_ip', 'Any IP')); ?></div>
                                <div class="text-muted u-fs-11"><?php echo (int)$tok['rate_limit_per_min']; ?> req/min</div>
                            </td>
                            <td>
                                <?php if (!empty($tok['last_used_at'])): ?>
                                    <div class="u-fs-12"><?php echo htmlspecialchars((string)$tok['last_used_at']); ?></div>
                                    <div class="text-muted u-fs-11"><?php echo htmlspecialchars((string)($tok['last_used_ip'] ?? '')); ?></div>
                                <?php else: ?>
                                    <span class="text-muted u-fs-12">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($tok['is_active'])): ?>
                                    <span class="badge badge-success"><?php echo htmlspecialchars(t('common.active', 'Active')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars(t('common.passive', 'Disabled')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <form method="POST" class="u-d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="toggle_token_id" value="<?php echo (int)$tok['id']; ?>">
                                    <button type="submit" class="btn btn-icon btn-secondary btn-sm" title="<?php echo htmlspecialchars(t('common.toggle', 'Toggle Active/Passive')); ?>">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                </form>
                                <form method="POST" class="u-d-inline" onsubmit="return confirm(<?php echo jsArg(t('api_keys.confirm_revoke', 'Revoke and permanently delete this API token?')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="delete_token_id" value="<?php echo (int)$tok['id']; ?>">
                                    <button type="submit" class="btn btn-icon btn-danger btn-sm" title="<?php echo htmlspecialchars(t('common.delete', 'Revoke')); ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>
