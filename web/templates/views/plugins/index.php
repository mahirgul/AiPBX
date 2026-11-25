<?php
/**
 * Admin -> Plugins: Official Plugin Catalog + Custom .zip Upload & Management.
 *
 * @var list<array<string, mixed>> $plugins
 * @var bool $catalog_on
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

<?php
$installedGrouped = [];
$catalogGrouped = [];
$installedCount = 0;
$catalogCount = 0;
foreach ($categories as $catKey => $catMeta) {
    foreach ($grouped[$catKey] ?? [] as $p) {
        if (!empty($p['installed'])) {
            $installedGrouped[$catKey][] = $p;
            $installedCount++;
        } else {
            $catalogGrouped[$catKey][] = $p;
            $catalogCount++;
        }
    }
}
$pluginTabs = [
    'installed' => ['label' => t('plugins.col_plugin', 'Plugin'), 'icon' => 'fa-puzzle-piece', 'badge' => (string) $installedCount],
    'catalog'   => ['label' => t('plugins.status_available', 'Available in Catalog'), 'icon' => 'fa-cloud-download-alt', 'badge' => (string) $catalogCount],
    'upload'    => ['label' => t('plugins.btn_upload_zip', 'Upload Custom Plugin (.zip)'), 'icon' => 'fa-file-zipper'],
];
$activePluginTab = (string) ($_GET['tab'] ?? 'installed');
if (!isset($pluginTabs[$activePluginTab])) {
    $activePluginTab = 'installed';
}
$renderPluginCategoryTables = static function (array $listByCat) use ($categories, $csrf_token): void {
    if ($listByCat === []) {
        echo '<p class="u-muted u-fs-13">' . htmlspecialchars(t('common.no_records', 'No records')) . '</p>';
        return;
    }
    foreach ($categories as $catKey => $catMeta):
        if (empty($listByCat[$catKey])) {
            continue;
        }
        ?>
        <div class="u-mb-16">
            <h3 class="card-title u-mb-8">
                <i class="<?php echo htmlspecialchars($catMeta['icon']); ?>" <?php echo !empty($catMeta['icon_style']) ? 'style="' . htmlspecialchars($catMeta['icon_style']) . '"' : ''; ?>></i>
                <?php echo htmlspecialchars($catMeta['label']); ?>
                <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($listByCat[$catKey]); ?></span>
            </h3>
            <?php echo uiTableOpen([
                ['label' => htmlspecialchars(t('plugins.col_plugin', 'Plugin')), 'class' => 'u-w-32pc'],
                htmlspecialchars(t('plugins.col_description', 'Description & Capabilities')),
                ['label' => htmlspecialchars(t('plugins.col_status', 'Status')), 'class' => 'u-w-14pc'],
                ['label' => htmlspecialchars(t('common.actions', 'Actions')), 'class' => 'text-right u-w-24pc'],
            ], ['search' => false]); ?>
                <?php foreach ($listByCat[$catKey] as $p): ?>
                    <tr>
                        <td>
                            <div class="u-d-flex u-ai-center u-gap-10">
                                <div class="u-w-36 u-h-36 u-r-8 u-flex-center u-jc-center u-fs-16 u-bg-bg-input">
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
                                            <a class="u-text-decoration-underline u-c-inherit u-fw-500" href="<?php echo htmlspecialchars($p['author_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($p['author']); ?></a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($p['author']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="u-fs-13 u-text-main u-mb-4">
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
                            <div class="u-d-inline-flex u-ai-center u-gap-6 u-wrap u-jc-flex-end">
                                <?php if ($p['enabled'] && !empty($p['settings_url'])): ?>
                                    <a href="<?php echo htmlspecialchars((string)$p['settings_url']); ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-sliders-h"></i> <?php echo htmlspecialchars(t('plugins.btn_configure', 'Open / Configure')); ?>
                                    </a>
                                <?php endif; ?>

                                <?php if (!empty($p['from_catalog']) || !empty($p['catalog_update'])): ?>
                                    <form method="POST" class="u-d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="install_catalog_plugin" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-cloud-download-alt"></i> <?php echo htmlspecialchars(!empty($p['catalog_update']) ? sprintf(t('plugins.btn_catalog_update'), $p['catalog_update']) : t('plugins.btn_install_enable', 'Install & Enable')); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!empty($p['from_catalog'])): ?>
                                <?php elseif (!$p['enabled']): ?>
                                    <form method="POST" class="u-d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="enable_plugin" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-power-off"></i> <?php echo htmlspecialchars($p['installed'] ? t('plugins.btn_enable', 'Enable') : t('plugins.btn_install_enable', 'Install & Enable')); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="u-d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="disable_plugin" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-pause"></i> <?php echo htmlspecialchars(t('plugins.btn_disable', 'Disable')); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($p['installed']): ?>
                                    <form method="POST" class="u-d-inline" onsubmit="return confirm(<?php echo jsArg(t('plugins.confirm_uninstall', 'Uninstall this plugin and remove its stored plugin settings?')); ?>);">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="uninstall_plugin" value="<?php echo htmlspecialchars($p['slug']); ?>">
                                        <button type="submit" class="btn btn-icon btn-danger btn-sm" title="<?php echo htmlspecialchars(t('plugins.btn_uninstall', 'Uninstall')); ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
        </div>
        <?php
    endforeach;
};
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-puzzle-piece', htmlspecialchars(t('plugins.title', 'Plugin Catalog & Manager')), 'pluginsHelpBox'); ?>
<span class="badge badge-success u-fs-11 u-ml-8"><?php echo $enabledCount; ?> <?php echo htmlspecialchars(t('common.active', 'Active')); ?></span>
            <span class="badge badge-primary u-fs-11 u-ml-4"><i class="fas fa-shield-alt"></i> <?php echo $officialCount; ?> <?php echo htmlspecialchars(t('plugins.badge_official', 'Official')); ?></span>
            <span class="badge badge-warning u-fs-11 u-ml-4"><i class="fas fa-users"></i> <?php echo $communityCount; ?> <?php echo htmlspecialchars(t('plugins.badge_community', 'Community')); ?></span>
<?php echo uiPageHeaderActions(); ?>
<div class="u-flex-center u-gap-8">
            <a href="/api-keys" class="btn btn-secondary btn-sm">
                <i class="fas fa-key"></i> <?php echo htmlspecialchars(t('sidebar.item_api_keys', 'REST API Keys')); ?>
            </a>
            <a href="/webhooks" class="btn btn-secondary btn-sm">
                <i class="fas fa-bolt"></i> <?php echo htmlspecialchars(t('sidebar.item_webhooks', 'Webhooks')); ?>
            </a>
        </div>
<?php echo uiPageHeaderClose(); ?>
<?php echo uiTabs($pluginTabs, $activePluginTab); ?>
<?php echo uiHelpBoxOpen('pluginsHelpBox', t('common.module_guide')); ?><?php echo htmlspecialchars(t('plugins.subtitle', 'AiPBX keeps the core PBX lean. Select and enable only the integrations, Cloud/OpenAI-compatible AI modules, or telemetry tools you need from the official catalog below, or upload a custom .zip plugin package.')); ?><?php echo uiHelpBoxClose(); ?>

    <div data-tab-pane="installed"<?php echo $activePluginTab === 'installed' ? '' : ' hidden'; ?>>
        <?php $renderPluginCategoryTables($installedGrouped); ?>
    </div>

    <div data-tab-pane="catalog"<?php echo $activePluginTab === 'catalog' ? '' : ' hidden'; ?>>
        <form method="POST" class="u-mb-12 u-d-flex u-wrap u-ai-center u-gap-12">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="community_catalog" value="1">
            <label class="u-fs-13"><input type="checkbox" name="catalog_on" value="1" onchange="this.form.submit()" <?php echo $catalog_on ? 'checked' : ''; ?>> <?php echo htmlspecialchars(t('plugins.catalog_label')); ?></label>
            <small class="u-hint"><?php echo htmlspecialchars(t('plugins.catalog_help')); ?></small>
        </form>
        <?php $renderPluginCategoryTables($catalogGrouped); ?>
    </div>

    <div data-tab-pane="upload"<?php echo $activePluginTab === 'upload' ? '' : ' hidden'; ?>>
        <form method="POST" enctype="multipart/form-data" class="u-d-flex u-wrap u-ai-center u-gap-12">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="upload_plugin_zip" value="1">
            <div class="u-flex-1 u-minw-260">
                <label class="form-label u-mb-4 u-fw-600">
                    <i class="fas fa-upload"></i> <?php echo htmlspecialchars(t('plugins.upload_label', 'Custom Plugin Package (.zip with plugin.json)')); ?>
                </label>
                <input type="file" name="plugin_zip" accept=".zip,application/zip" class="form-control" required>
            </div>
            <div class="u-pt-20">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars(t('plugins.btn_install_zip', 'Verify & Install Plugin')); ?>
                </button>
            </div>
        </form>
    </div>
</div>
