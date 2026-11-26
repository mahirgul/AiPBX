<?php
/** Above a page the AI setup wizard opened: how far the setup is, and the way back (BuiltinAiAgentService::wizardBar). */
$wb = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<div class="alert alert-info u-d-flex u-jc-space-between u-ai-center u-wrap u-gap-8 u-mb-12" role="status">
    <span><i class="fas fa-list-check"></i>
        <strong><?php echo $wb(t('builtin_ai_agent.wizard_title')); ?></strong> ·
        <?php echo $wb(sprintf(t('builtin_ai_agent.wizard_progress'), $wizardBar['done'], $wizardBar['total'])); ?> ·
        <?php echo $wb(sprintf(t('builtin_ai_agent.wizard_next'), t('builtin_ai_agent.wizard_' . $wizardBar['step']))); ?></span>
    <span class="u-d-flex u-gap-8">
        <a href="/ai-agent?tab=wizard" class="btn btn-primary btn-sm"><i class="fas fa-arrow-left"></i> <?php echo $wb(t('builtin_ai_agent.wizard_back')); ?></a>
        <a href="?wizard_bar=off" class="btn btn-secondary btn-sm" title="<?php echo $wb(t('builtin_ai_agent.wizard_bar_close')); ?>" aria-label="<?php echo $wb(t('builtin_ai_agent.wizard_bar_close')); ?>"><i class="fas fa-xmark"></i></a>
    </span>
</div>
