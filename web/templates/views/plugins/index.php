<?php
/**
 * Admin -> Plugins: Official Plugin Catalog + Custom .zip Upload & Management.
 *
 * @var list<array<string, mixed>> $plugins
 * @var string $csrf_token
 */

$enabledCount = count(array_filter($plugins, fn($p) => !empty($p['enabled'])));
$officialCount = count(array_filter($plugins, fn($p) => ($p['type'] ?? 'official') === 'official'));
$communityCount = count(array_filter($plugins, fn($p) => ($p['type'] ?? 'official') === 'community'));
$categories = [
    'integration' => ['label' => t('plugins.cat_integrations', 'Integrations & SSO'), 'icon' => 'fas fa-plug u-primary'],
    'ai'          => ['label' => t('plugins.cat_ai', 'Cloud AI, OpenAI-Compatible & Speech'), 'icon' => 'fas fa-wand-magic-sparkles', 'icon_style' => 'color: var(--purple);'],
    'telemetry'   => ['label' => t('plugins.cat_telemetry', 'Signaling, Telemetry & Network'), 'icon' => 'fas fa-satellite-dish u-info'],
    'custom'      => ['label' => t('plugins.cat_custom', 'Custom & Community Plugins'), 'icon' => 'fas fa-box-open u-warning'],
];

$grouped = ['integration' => [], 'ai' => [], 'telemetry' => [], 'custom' => []];
foreach ($plugins as $p) {
    $cat = $p['source'] === 'custom' ? 'custom' : ($p['category'] ?? 'integration');
    if (!isset($grouped[$cat])) {
        $cat = 'custom';
    }
    $grouped[$cat][] = $p;
}
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/plugins.css'); ?>">

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-puzzle-piece u-primary"></i> <?php echo htmlspecialchars(t('plugins.title', 'Plugin Catalog & Manager')); ?>
            <span class="badge badge-success u-fs-11 u-ml-8"><?php echo $enabledCount; ?> <?php echo htmlspecialchars(t('common.active', 'Active')); ?></span>
            <span class="badge badge-primary u-fs-11 u-ml-4"><i class="fas fa-shield-alt"></i> <?php echo $officialCount; ?> <?php echo htmlspecialchars(t('plugins.badge_official', 'Official')); ?></span>
            <span class="badge badge-warning u-fs-11 u-ml-4"><i class="fas fa-users"></i> <?php echo $communityCount; ?> <?php echo htmlspecialchars(t('plugins.badge_community', 'Community')); ?></span>
        </div>
        <div class="u-flex-center" style="gap: 8px;">
            <a href="/api-keys" class="btn btn-secondary btn-sm">
                <i class="fas fa-key"></i> <?php echo htmlspecialchars(t('sidebar.item_api_keys', 'REST API Keys')); ?>
            </a>
            <a href="/webhooks" class="btn btn-secondary btn-sm">
                <i class="fas fa-bolt"></i> <?php echo htmlspecialchars(t('sidebar.item_webhooks', 'Webhooks')); ?>
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('uploadPluginBox').style.display = document.getElementById('uploadPluginBox').style.display === 'none' ? 'block' : 'none';">
                <i class="fas fa-file-zipper"></i> <?php echo htmlspecialchars(t('plugins.btn_upload_zip', 'Upload Custom Plugin (.zip)')); ?>
            </button>
        </div>
    </div>

    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); color: var(--text-muted); font-size: 13px; line-height: 1.6;">
        <i class="fas fa-info-circle u-primary"></i>
        <?php echo htmlspecialchars(t('plugins.subtitle', 'AiPBX keeps the core PBX lean. Select and enable only the integrations, Cloud/OpenAI-compatible AI modules, or telemetry tools you need from the official catalog below, or upload a custom .zip plugin package.')); ?>
    </div>

    <div id="uploadPluginBox" style="display: none; padding: 18px 20px; background: var(--bg-subtle, rgba(2,132,199,0.05)); border-bottom: 1px solid var(--border-color);">
        <form method="POST" enctype="multipart/form-data" style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="upload_plugin_zip" value="1">
            <div style="flex: 1; min-width: 260px;">
                <label class="form-label" style="margin-bottom: 4px; font-weight: 600;">
                    <i class="fas fa-upload"></i> <?php echo htmlspecialchars(t('plugins.upload_label', 'Custom Plugin Package (.zip with plugin.json)')); ?>
                </label>
                <input type="file" name="plugin_zip" accept=".zip,application/zip" class="form-control" required>
            </div>
            <div style="padding-top: 20px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars(t('plugins.btn_install_zip', 'Verify & Install Plugin')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($categories as $catKey => $catMeta): ?>
    <?php if (empty($grouped[$catKey])) continue; ?>
    <div class="card u-mb-20">
        <div class="card-header">
            <div class="card-title">
                <i class="<?php echo htmlspecialchars($catMeta['icon']); ?>" <?php echo !empty($catMeta['icon_style']) ? 'style="' . htmlspecialchars($catMeta['icon_style']) . '"' : ''; ?>></i>
                <?php echo htmlspecialchars($catMeta['label']); ?>
                <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($grouped[$catKey]); ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="data-table no-dt">
                <thead>
                    <tr>
                        <th style="width: 32%;"><?php echo htmlspecialchars(t('plugins.col_plugin', 'Plugin')); ?></th>
                        <th><?php echo htmlspecialchars(t('plugins.col_description', 'Description & Capabilities')); ?></th>
                        <th style="width: 14%;"><?php echo htmlspecialchars(t('plugins.col_status', 'Status')); ?></th>
                        <th class="text-right" style="width: 24%;"><?php echo htmlspecialchars(t('common.actions', 'Actions')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped[$catKey] as $p): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 36px; height: 36px; border-radius: 8px; background: var(--bg-hover, rgba(148,163,184,0.12)); display: flex; align-items: center; justify-content: center; font-size: 16px;">
                                        <i class="<?php echo htmlspecialchars($p['icon']); ?>" <?php echo $p['icon_style'] !== '' ? 'style="' . htmlspecialchars($p['icon_style']) . '"' : ''; ?>></i>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                                        <span class="badge badge-secondary u-fs-11">v<?php echo htmlspecialchars($p['version']); ?></span>
                                        <?php if (($p['type'] ?? 'official') === 'official'): ?>
                                            <span class="badge badge-primary u-fs-11"><i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars(t('plugins.badge_official', 'Official')); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-warning u-fs-11"><i class="fas fa-users"></i> <?php echo htmlspecialchars(t('plugins.badge_community', 'Community')); ?></span>
                                        <?php endif; ?>
                                        <div class="text-muted u-fs-11 u-mt-2">
                                            <code><?php echo htmlspecialchars($p['slug']); ?></code>
                                            · <?php echo htmlspecialchars(t('common.by', 'by')); ?>
                                            <?php if (!empty($p['author_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($p['author_url']); ?>" target="_blank" rel="noopener noreferrer" style="text-decoration: underline; color: inherit; font-weight: 500;"><?php echo htmlspecialchars($p['author']); ?></a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($p['author']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 13px; color: var(--text-main); margin-bottom: 4px;">
                                    <?php echo htmlspecialchars($p['description']); ?>
                                </div>
                                <?php if (!empty($p['hooks']) || !empty($p['scopes'])): ?>
                                    <details class="plugin-tech">
                                        <summary><?php echo htmlspecialchars(t('plugins.tech_details', 'Technical details')); ?></summary>
                                        <?php if (!empty($p['hooks'])): ?>
                                            <div><span class="text-muted"><?php echo htmlspecialchars(t('plugins.tech_hooks', 'Hooks')); ?>:</span> <code><?php echo htmlspecialchars(implode(', ', array_map('strval', array_keys($p['hooks'])))); ?></code></div>
                                        <?php endif; ?>
                                        <?php if (!empty($p['scopes'])): ?>
                                            <div><span class="text-muted"><?php echo htmlspecialchars(t('plugins.tech_scopes', 'Permissions')); ?>:</span> <code><?php echo htmlspecialchars(implode(', ', array_map('strval', $p['scopes']))); ?></code></div>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['enabled']): ?>
                                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars(t('common.active', 'Enabled')); ?></span>
                                <?php elseif ($p['installed']): ?>
                                    <span class="badge badge-warning"><i class="fas fa-pause-circle"></i> <?php echo htmlspecialchars(t('common.passive', 'Disabled')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><i class="fas fa-cloud-download-alt"></i> <?php echo htmlspecialchars(t('plugins.status_available', 'Available in Catalog')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div style="display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                    <?php if ($p['enabled'] && !empty($p['settings_url'])): ?>
                                        <a href="<?php echo htmlspecialchars((string)$p['settings_url']); ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-sliders-h"></i> <?php echo htmlspecialchars(t('plugins.btn_configure', 'Open / Configure')); ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!$p['enabled']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="enable_plugin" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-power-off"></i> <?php echo htmlspecialchars($p['installed'] ? t('plugins.btn_enable', 'Enable') : t('plugins.btn_install_enable', 'Install & Enable')); ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="disable_plugin" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-pause"></i> <?php echo htmlspecialchars(t('plugins.btn_disable', 'Disable')); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($p['installed']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm(<?php echo jsArg(t('plugins.confirm_uninstall', 'Uninstall this plugin and remove its stored plugin settings?')); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="uninstall_plugin" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="<?php echo htmlspecialchars(t('plugins.btn_uninstall', 'Uninstall')); ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
