<?php
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$total = count($steps);
$done = count(array_filter($steps, static fn ($s) => $s['done']));
// The first open step is the one to work on: it stays unfolded, the others fold.
$current = null;
foreach ($steps as $key => $step) {
    if (!$step['done']) {
        $current = $key;
        break;
    }
}
$n = 0;
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-list-check', t('builtin_ai_agent.wizard_title'), 'aiWizardHelp'); ?>
    <?php echo uiPageHeaderActions(); ?>
    <span class="badge <?php echo $done === $total ? 'badge-success' : 'badge-secondary'; ?>"><?php echo $h(sprintf(t('builtin_ai_agent.wizard_progress'), $done, $total)); ?></span>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('aiWizardHelp', t('builtin_ai_agent.wizard_title')); ?>
    <p><?php echo $h(t('builtin_ai_agent.wizard_help')); ?></p>
    <?php echo uiHelpBoxClose(); ?>
    <div class="u-card-pad">
        <progress class="u-mb-12 u-w-100" max="<?php echo $total; ?>" value="<?php echo $done; ?>" aria-label="<?php echo $h(sprintf(t('builtin_ai_agent.wizard_progress'), $done, $total)); ?>"></progress>
        <?php foreach ($steps as $key => $step): $n++; ?>
            <details class="ai-wizard-step u-mb-8"<?php echo $key === $current ? ' open' : ''; ?>>
                <summary class="u-fw-600">
                    <?php if ($step['done']): ?>
                        <i class="fas fa-circle-check u-success" aria-hidden="true"></i>
                    <?php elseif ($key === $current): ?>
                        <i class="fas fa-circle-play u-primary" aria-hidden="true"></i>
                    <?php else: ?>
                        <i class="far fa-circle u-muted" aria-hidden="true"></i>
                    <?php endif; ?>
                    <?php echo $n . '. ' . $h(t('builtin_ai_agent.wizard_' . $key)); ?>
                    <span class="sr-only"><?php echo $h($step['done'] ? t('builtin_ai_agent.wizard_done') : t('builtin_ai_agent.wizard_open')); ?></span>
                    <?php if ($step['info'] !== ''): ?><span class="u-muted u-fs-12"> — <?php echo $h($step['info']); ?></span><?php endif; ?>
                </summary>
                <div class="u-mt-6 u-pl-24">
                    <p class="u-fs-13 u-mt-0"><?php echo $h(t('builtin_ai_agent.wizard_' . $key . '_help')); ?></p>
                    <?php if ($key === 'plugins'): ?>
                        <div class="u-flex u-wrap u-gap-8 u-mb-8">
                            <?php foreach ($plugins as $slug => $enabled): ?>
                                <?php if ($enabled): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo $h($slug); ?></span>
                                <?php else: ?>
                                    <form method="post" class="u-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                        <button type="submit" name="enable_plugin" value="<?php echo $h($slug); ?>" class="btn btn-secondary btn-sm"><i class="fas fa-power-off"></i> <?php echo $h(sprintf(t('builtin_ai_agent.wizard_enable'), $slug)); ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($key === 'test' && ($step['number'] ?? '') !== ''): ?>
                        <p class="u-fs-13"><strong><?php echo $h(sprintf(t('builtin_ai_agent.wizard_call'), $step['number'])); ?></strong></p>
                    <?php endif; ?>
                    <div class="u-flex u-wrap u-gap-8">
                        <?php foreach ($step['links'] as $href => $label): ?>
                            <a href="<?php echo $h($href); ?>" class="btn btn-sm <?php echo $key === $current ? 'btn-primary' : 'btn-secondary'; ?>"><?php echo $h(t($label)); ?> <i class="fas fa-arrow-right"></i></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
        <?php if ($current === null): ?>
            <p class="u-success u-fw-600"><i class="fas fa-flag-checkered"></i> <?php echo $h(t('builtin_ai_agent.wizard_finished')); ?></p>
        <?php endif; ?>
    </div>
</div>
