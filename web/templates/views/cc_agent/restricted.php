<div class="card" style="margin: 40px auto; max-width: 600px; text-align: center; padding: 30px;">
    <i class="fas fa-user-slash" style="font-size: 48px; color: var(--warning); margin-bottom: 16px;"></i>
    <h3 style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;"><?php echo t('cc_agent.restricted_title'); ?></h3>
    <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
        <?php echo t('cc_agent.restricted_body'); ?>
    </p>
    <div style="display: flex; gap: 10px; justify-content: center;">
        <a href="/cc-supervisor" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;"><i class="fas fa-chart-line"></i> <?php echo t('cc_agent.go_supervisor'); ?></a>
        <a href="/" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;"><i class="fas fa-home"></i> <?php echo t('cc_agent.go_home'); ?></a>
    </div>
</div>
