<?php
/**
 * A plugin's page for signed-in users (/apps/<slug>), drawn by the plugin's user/page.php with
 * $slug, $settings, $user and $csrf_token. A failing page shows its error and leaves the portal up.
 *
 * @var string $page_path
 * @var string $slug
 * @var array $settings
 * @var array $user
 * @var string $csrf_token
 */
ob_start();
try {
    require $page_path;
    echo ob_get_clean();
} catch (\Throwable $pluginUiErr) {
    ob_end_clean();
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Plugin UI Error: '
        . htmlspecialchars($pluginUiErr->getMessage()) . '</div>';
}
