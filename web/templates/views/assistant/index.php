<?php
/** @var string $csrf */
/** @var bool $is_admin */
/** @var bool $can_change */
/** @var bool $has_extension */
/** @var array $suggestions */
/** @var string $echo_askable */
/** @var array{active_calls:int, registered_phones:int, warnings:int} $status_badges */
/** @var bool $show_dashboard_link */

$texts = [];
foreach ([
    'title', 'welcome_heading', 'placeholder', 'ask', 'thinking', 'new_chat',
    'go_dashboard', 'badge_active_calls', 'badge_registered',
    'badge_warnings', 'preview_title', 'preview_apply', 'preview_discard', 'applied',
    'cancelled', 'undo', 'undo_done', 'err_generic', 'risk_low', 'risk_medium',
    'echo_askable', 'unknown_intro', 'nav_intro', 'no_extension_notice', 'knowledge_sources',
] as $k) {
    $texts[$k] = t('assistant.' . $k);
}
$texts['no_settings_results'] = t('help.no_results');
?>

<div class="assistant-shell">
  <section class="assistant-main is-welcome" id="asst-main">
    <div class="u-d-flex u-jc-flex-end u-w-100p u-mb-8">
      <button type="button" class="btn btn-secondary btn-sm" id="asst-new-chat">
        <i class="fas fa-plus" aria-hidden="true"></i> <?php echo htmlspecialchars($texts['new_chat']); ?>
      </button>
    </div>
    <div class="assistant-welcome-head">
      <div class="assistant-welcome-logo"><i class="fas fa-wand-magic-sparkles"></i></div>
      <h1 class="assistant-welcome-title"><?php echo htmlspecialchars($texts['welcome_heading']); ?> <?php echo uiExperimentalBadge(); ?></h1>
    </div>

    <?php if ($is_admin): ?>
      <div class="assistant-badges">
        <span class="assistant-badge">
          <i class="fas fa-phone u-primary"></i>
          <?php echo htmlspecialchars($texts['badge_active_calls']); ?>: <strong><?php echo (int) $status_badges['active_calls']; ?></strong>
        </span>
        <span class="assistant-badge">
          <i class="fas fa-circle-check u-success"></i>
          <?php echo htmlspecialchars($texts['badge_registered']); ?>: <strong><?php echo (int) $status_badges['registered_phones']; ?></strong>
        </span>
        <span class="assistant-badge">
          <i class="fas fa-triangle-exclamation u-warning"></i>
          <?php echo htmlspecialchars($texts['badge_warnings']); ?>: <strong><?php echo (int) $status_badges['warnings']; ?></strong>
        </span>
      </div>
    <?php endif; ?>

    <div class="assistant-messages" id="asst-messages"></div>

    <div class="assistant-composer">
      <div class="assistant-input-wrap">
        <input type="text" id="asst-input" autocomplete="off"
               placeholder="<?php echo htmlspecialchars($texts['placeholder']); ?>">
        <button type="button" class="btn btn-primary" id="asst-send">
          <i class="fas fa-paper-plane"></i> <?php echo htmlspecialchars($texts['ask']); ?>
        </button>
      </div>

      <?php if (!$is_admin && !$has_extension && $can_change): ?>
        <div class="u-fs-12 u-muted u-mt-6"><?php echo htmlspecialchars($texts['no_extension_notice']); ?></div>
      <?php endif; ?>

      <div class="assistant-suggestions" id="asst-suggestions">
        <?php foreach ($suggestions as $s): ?>
          <button type="button" class="assistant-suggestion" data-suggestion="<?php echo htmlspecialchars((string) $s); ?>">
            <?php echo htmlspecialchars((string) $s); ?>
          </button>
        <?php endforeach; ?>
      </div>

      <?php if ($show_dashboard_link || !empty($is_admin)): ?>
        <div class="assistant-footer-links">
          <?php if ($show_dashboard_link): ?>
            <a href="/dashboard" class="u-muted"><i class="fas fa-tachometer-alt"></i> <?php echo htmlspecialchars($texts['go_dashboard']); ?></a>
          <?php endif; ?>
          <?php if (!empty($is_admin)): ?>
            <a href="/ai-assistant" class="u-muted u-ml-8"><i class="fas fa-sliders"></i> <?php echo htmlspecialchars(t('common.settings', 'Settings')); ?></a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
window.ASST_CONFIG = {
  csrf: <?php echo json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
  texts: <?php echo json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
  echoAskable: <?php echo json_encode($echo_askable, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
  canChange: <?php echo json_encode((bool) $can_change, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
  initialQuery: <?php echo json_encode((string) ($initial_query ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
  settingsMatches: <?php echo json_encode($settings_matches ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
};
</script>
<script src="<?php echo asset('/assets/js/assistant.js'); ?>"></script>
