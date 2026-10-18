<?php
/**
 * Admin -> Plugins -> Detail / Settings Page (`/plugins/<slug>`)
 *
 * @var array<string, mixed> $plugin
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $masked_settings
 * @var string|null $custom_view_path
 * @var string $csrf_token
 */

$schema = $plugin['settings_schema'] ?? [];
$lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';
?>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="<?php echo htmlspecialchars($plugin['icon']); ?>" <?php echo $plugin['icon_style'] !== '' ? 'style="' . htmlspecialchars($plugin['icon_style']) . '"' : ''; ?>></i>
            <?php echo htmlspecialchars($plugin['name']); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8">v<?php echo htmlspecialchars($plugin['version']); ?></span>
            <?php if ($plugin['enabled']): ?>
                <span class="badge badge-success u-fs-11 u-ml-4"><?php echo htmlspecialchars(t('common.active', 'Enabled')); ?></span>
            <?php else: ?>
                <span class="badge badge-warning u-fs-11 u-ml-4"><?php echo htmlspecialchars(t('common.passive', 'Disabled')); ?></span>
            <?php endif; ?>
        </div>
        <div class="u-flex-center" style="gap: 8px;">
            <a href="/plugins" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars(t('plugins.back_to_catalog', 'Back to Plugin Catalog')); ?>
            </a>
        </div>
    </div>

    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); color: var(--text-muted); font-size: 13px;">
        <?php echo htmlspecialchars($plugin['description']); ?>
    </div>

    <?php if (!empty($schema)): ?>
        <form method="POST" style="padding: 20px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="save_plugin_settings" value="1">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                <?php foreach ($schema as $field): ?>
                    <?php
                    $fKey = (string)($field['key'] ?? '');
                    if ($fKey === '') continue;
                    $fLabel = (string)($field['label_i18n'][$lang] ?? $field['label'] ?? $fKey);
                    $fHelp = (string)($field['help_i18n'][$lang] ?? $field['help'] ?? '');
                    $fType = (string)($field['type'] ?? 'text');
                    $isSecret = !empty($field['secret']);
                    $curVal = (string)($settings[$fKey] ?? ($field['default'] ?? ''));
                    $maskedVal = (string)($masked_settings[$fKey] ?? '');
                    ?>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 600;">
                            <?php echo htmlspecialchars($fLabel); ?>
                            <?php if ($isSecret && $maskedVal !== ''): ?>
                                <span class="badge badge-info u-fs-11"><?php echo htmlspecialchars($maskedVal); ?></span>
                            <?php endif; ?>
                        </label>
                        <?php if ($fType === 'boolean' || $fType === 'checkbox'): ?>
                            <div style="margin-top: 6px;">
                                <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="<?php echo htmlspecialchars($fKey); ?>" value="1" <?php echo ($curVal === '1' || $curVal === 'true' || $curVal === 'yes') ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($fHelp !== '' ? $fHelp : $fLabel); ?></span>
                                </label>
                            </div>
                        <?php elseif ($fType === 'select' && !empty($field['options']) && is_array($field['options'])): ?>
                            <select name="<?php echo htmlspecialchars($fKey); ?>" class="form-control">
                                <?php foreach ($field['options'] as $optVal => $optLabel): ?>
                                    <option value="<?php echo htmlspecialchars((string)$optVal); ?>" <?php echo $curVal === (string)$optVal ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string)$optLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($fType === 'textarea'): ?>
                            <textarea name="<?php echo htmlspecialchars($fKey); ?>" class="form-control" rows="3"><?php echo htmlspecialchars($isSecret ? '' : $curVal); ?></textarea>
                        <?php else: ?>
                            <input
                                type="<?php echo $isSecret ? 'password' : ($fType === 'number' ? 'number' : 'text'); ?>"
                                name="<?php echo htmlspecialchars($fKey); ?>"
                                class="form-control"
                                value="<?php echo htmlspecialchars($isSecret ? '' : $curVal); ?>"
                                placeholder="<?php echo htmlspecialchars($isSecret && $maskedVal !== '' ? '•••••••• (' . $maskedVal . ')' : (string)($field['placeholder'] ?? '')); ?>"
                            >
                        <?php endif; ?>
                        <?php if ($fHelp !== '' && $fType !== 'boolean' && $fType !== 'checkbox'): ?>
                            <small class="text-muted"><?php echo htmlspecialchars($fHelp); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('common.save', 'Save Settings')); ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if ($custom_view_path !== null && is_file($custom_view_path)): ?>
    <?php
    ob_start();
    try {
        require $custom_view_path;
        echo ob_get_clean();
    } catch (\Throwable $pluginUiErr) {
        ob_end_clean();
        echo '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Plugin UI Error: '
            . htmlspecialchars($pluginUiErr->getMessage()) . '</div>';
    }
    ?>
<?php endif; ?>
