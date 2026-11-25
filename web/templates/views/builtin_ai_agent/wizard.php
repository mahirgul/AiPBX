<?php
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$total = count($steps);
$done = count(array_filter($steps, static fn ($s) => $s['done']));
// The first open step is the one to work on: it is open and highlighted, the others are one line.
$current = null;
foreach ($steps as $key => $step) {
    if (!$step['done']) {
        $current = $key;
        break;
    }
}
$icons = ['plugins' => '🧩', 'models' => '🧠', 'voice' => '🔊', 'knowledge' => '📚', 'agent' => '🤖', 'number' => '☎️', 'test' => '📞'];
$n = 0;
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/ai_wizard.css'); ?>">
<div class="card">
    <?php echo uiPageHeaderOpen('fa-list-check', t('builtin_ai_agent.wizard_title'), 'aiWizardHelp'); ?>
    <span class="badge <?php echo $done === $total ? 'badge-success' : 'badge-secondary'; ?>"><?php echo $h(sprintf(t('builtin_ai_agent.wizard_progress'), $done, $total)); ?></span>
    <?php echo uiPageHeaderActions(); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('aiWizardHelp', t('builtin_ai_agent.wizard_title')); ?>
    <p><?php echo $h(t('builtin_ai_agent.wizard_help')); ?></p>
    <?php echo uiHelpBoxClose(); ?>
    <div class="u-card-pad">
        <progress class="aiw-progress" max="<?php echo $total; ?>" value="<?php echo $done; ?>" aria-label="<?php echo $h(sprintf(t('builtin_ai_agent.wizard_progress'), $done, $total)); ?>"></progress>
        <?php if ($current === null): ?>
            <div class="aiw-finished"><span class="aiw-emoji" aria-hidden="true">🎉</span> <?php echo $h(t('builtin_ai_agent.wizard_finished')); ?></div>
        <?php endif; ?>
        <ol class="aiw-steps">
            <?php foreach ($steps as $key => $step): $n++;
                $state = $step['done'] ? 'is-done' : ($key === $current ? 'is-current' : 'is-todo'); ?>
                <li class="aiw-step <?php echo $state; ?>">
                    <span class="aiw-marker" aria-hidden="true"><?php echo $step['done'] ? '✓' : $n; ?></span>
                    <div class="aiw-card">
                        <div class="aiw-head">
                            <span class="aiw-emoji" aria-hidden="true"><?php echo $icons[$key] ?? '•'; ?></span>
                            <strong class="aiw-title"><?php echo $h(t('builtin_ai_agent.wizard_' . $key)); ?></strong>
                            <span class="aiw-chip"><?php echo $h($step['done'] ? t('builtin_ai_agent.wizard_done') : ($key === $current ? t('builtin_ai_agent.wizard_current') : t('builtin_ai_agent.wizard_open'))); ?></span>
                        </div>
                        <?php if ($step['info'] !== ''): ?><div class="aiw-info"><?php echo $h($step['info']); ?></div><?php endif; ?>
                        <?php if ($key === $current): ?>
                            <p class="aiw-help"><?php echo $h(t('builtin_ai_agent.wizard_' . $key . '_help')); ?></p>
                            <?php if ($key === 'test' && ($step['number'] ?? '') !== ''): ?>
                                <p class="aiw-help"><strong><?php echo $h(sprintf(t('builtin_ai_agent.wizard_call'), $step['number'])); ?></strong></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="aiw-actions">
                            <?php if ($key === 'plugins'): ?>
                                <?php foreach ($plugins as $slug => $enabled): ?>
                                    <?php if (!$enabled): ?>
                                        <form method="post" class="u-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                            <button type="submit" name="enable_plugin" value="<?php echo $h($slug); ?>" class="btn <?php echo $key === $current ? 'btn-sm btn-primary' : 'btn-xs btn-secondary'; ?>"><i class="fas fa-power-off"></i> <?php echo $h(sprintf(t('builtin_ai_agent.wizard_enable'), $slug)); ?></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php foreach ($step['links'] as $href => $label): ?>
                                <a href="<?php echo $h($href); ?>" class="btn <?php echo $key === $current ? 'btn-sm btn-primary' : 'btn-xs btn-ghost'; ?>"><?php echo $h(t($label)); ?> <i class="fas fa-arrow-right"></i></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
