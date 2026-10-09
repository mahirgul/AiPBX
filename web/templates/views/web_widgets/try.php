<?php
/**
 * "Try it": a bare page on the portal's own origin with the widget's embed
 * code, so an administrator can test a widget before putting it on a website.
 *
 * @var array $widget
 * @var string $embed
 */
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars((string) $widget['language']); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?php echo htmlspecialchars(t('web_widgets.try') . ' — ' . $widget['name']); ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 40px 20px; background: #f3f4f6; color: #111827; }
        main { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        code { display: block; white-space: pre-wrap; word-break: break-all; background: #f9fafb; padding: 12px; border-radius: 8px; font-size: 12px; }
    </style>
</head>
<body>
<main>
    <h1><?php echo htmlspecialchars((string) $widget['name']); ?></h1>
    <p><?php echo t('web_widgets.try_desc'); ?></p>
    <?php if ((int) $widget['is_active'] !== 1): ?>
        <p><strong><?php echo t('web_widgets.try_inactive'); ?></strong></p>
    <?php endif; ?>
    <code><?php echo htmlspecialchars($embed); ?></code>
</main>
<?php echo $embed; ?>
</body>
</html>
