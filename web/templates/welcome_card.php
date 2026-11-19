<?php
/** First steps on the user's start page (WelcomeService); closed for good by the user. */
$welcomeGroups = WelcomeService::groups();
if ($welcomeGroups === []) {
    return;
}
?>
<div class="card" id="welcomeCard">
    <div class="u-d-flex u-jc-space-between u-ai-center u-wrap u-gap-8 u-mb-10">
        <h3 class="card-title u-m-0"><i class="fas fa-hand-sparkles u-primary"></i> <?php echo t('welcome.title'); ?></h3>
        <button type="button" class="btn btn-secondary btn-sm" onclick="dismissWelcomeCard()"><i class="fas fa-eye-slash"></i> <?php echo t('welcome.dismiss'); ?></button>
    </div>
    <p class="u-muted u-fs-13 u-mt-0"><?php echo t('welcome.intro'); ?></p>
    <?php foreach ($welcomeGroups as $g): ?>
        <div class="u-fs-12 u-fw-700 u-muted u-mb-6"><?php echo htmlspecialchars($g['title']); ?></div>
        <div class="u-d-flex u-wrap u-gap-8 u-mb-12">
            <?php foreach ($g['links'] as $l): ?>
                <?php if (isset($l['onclick'])): ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="<?php echo htmlspecialchars($l['onclick']); ?>"><i class="fas <?php echo htmlspecialchars($l['icon']); ?>"></i> <?php echo htmlspecialchars($l['label']); ?></button>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($l['href']); ?>" class="btn btn-secondary btn-sm"><i class="fas <?php echo htmlspecialchars($l['icon']); ?>"></i> <?php echo htmlspecialchars($l['label']); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
