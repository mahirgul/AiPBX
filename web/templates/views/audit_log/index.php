<div class="card">
    <?php echo uiPageHeaderOpen('fa-shield-alt', t('audit_log.header_title_pbx'), 'auditHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('auditHelpBox', t('audit_log.help_title')); ?>
        <?php echo t('audit_log.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

    <form method="GET" autocomplete="off" class="u-d-flex u-gap-12 u-mb-20 u-wrap u-ai-center">
        <input type="hidden" name="login_status" value="<?php echo htmlspecialchars($login_status_filter); ?>">
        <input type="hidden" name="login_search" value="<?php echo htmlspecialchars($login_search_query); ?>">
        <select name="date_range" class="form-control form-control-sm u-w-auto">
            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_today'); ?></option>
            <option value="yesterday" <?php echo $date_filter === 'yesterday' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_yesterday'); ?></option>
            <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_week'); ?></option>
            <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_month'); ?></option>
            <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>><?php echo t('audit_log.range_all'); ?></option>
        </select>

        <?php
        $resolveAuditDomainLabel = static fn(string $d): string => match ($d) {
            'privacy'  => t('sidebar.item_privacy', $d),
            'security' => t('sidebar.group_security', $d),
            'plugins'  => t('sidebar.item_plugins', $d),
            default    => t('pending_sync.domain_' . $d, $d),
        };
        ?>
        <select name="domain" class="form-control form-control-sm u-w-auto">
            <option value=""><?php echo t('audit_log.all_domains'); ?></option>
            <?php foreach (array_keys($domain_map) as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $domain_filter === $d ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($resolveAuditDomainLabel((string)$d)); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="action" class="form-control form-control-sm u-w-auto">
            <option value=""><?php echo t('audit_log.all_actions'); ?></option>
            <option value="create" <?php echo $action_filter === 'create' ? 'selected' : ''; ?>><?php echo t('pending_sync.action_create'); ?></option>
            <option value="update" <?php echo $action_filter === 'update' ? 'selected' : ''; ?>><?php echo t('pending_sync.action_update'); ?></option>
            <option value="delete" <?php echo $action_filter === 'delete' ? 'selected' : ''; ?>><?php echo t('pending_sync.action_delete'); ?></option>
            <option value="apply" <?php echo $action_filter === 'apply' ? 'selected' : ''; ?>><?php echo t('audit_log.action_apply'); ?></option>
            <option value="apply_failed" <?php echo $action_filter === 'apply_failed' ? 'selected' : ''; ?>><?php echo t('audit_log.action_apply_failed'); ?></option>
            <option value="reload" <?php echo $action_filter === 'reload' ? 'selected' : ''; ?>><?php echo t('audit_log.action_reload'); ?></option>
            <option value="restart" <?php echo $action_filter === 'restart' ? 'selected' : ''; ?>><?php echo t('audit_log.action_restart'); ?></option>
            <option value="reload_failed" <?php echo $action_filter === 'reload_failed' ? 'selected' : ''; ?>><?php echo t('audit_log.action_reload_failed'); ?></option>
            <option value="restart_failed" <?php echo $action_filter === 'restart_failed' ? 'selected' : ''; ?>><?php echo t('audit_log.action_restart_failed'); ?></option>
        </select>

        <select name="user" class="form-control form-control-sm u-w-auto">
            <option value=""><?php echo t('audit_log.all_users'); ?></option>
            <?php foreach ($user_map as $uid => $uname): ?>
                <option value="<?php echo htmlspecialchars((string)$uid); ?>" <?php echo $user_filter === (string)$uid ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($uname); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="u-relative u-maxw-220">
            <i class="fas fa-search u-pos-absolute u-left-10 u-top-50pc u-transform-translatey-50pc u-muted u-fs-12 u-pointer-events-none"></i>
            <input type="text" name="search" class="form-control form-control-sm u-pl-28" placeholder="<?php echo t('audit_log.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search_query); ?>">
        </div>

        <button type="submit" class="btn btn-icon btn-primary btn-sm" title="<?php echo t('queue_logs.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
    </form>

    <?php echo uiTableOpen([
        t('audit_log.col_date'),
        t('audit_log.col_user'),
        t('audit_log.col_domain'),
        t('audit_log.col_action'),
        t('audit_log.col_change'),
        ['label' => t('audit_log.col_ip'), 'class' => 'col-hide-mobile'],
    ]); ?>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="u-text-center u-muted u-p-30">
                            <i class="fas fa-search-minus u-fs-32 u-mb-8" style="display: block;"></i>
                            <?php echo t('audit_log.empty'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                        $auditActionBadgeMap = ['create' => 'success', 'update' => 'warning', 'delete' => 'danger', 'apply' => 'primary', 'apply_failed' => 'danger', 'reload' => 'info', 'restart' => 'danger', 'reload_failed' => 'danger', 'restart_failed' => 'danger'];
                        $auditActionLabelMap = [
                            'create' => t('pending_sync.action_create'),
                            'update' => t('pending_sync.action_update'),
                            'delete' => t('pending_sync.action_delete'),
                            'apply' => t('audit_log.action_apply'),
                            'apply_failed' => t('audit_log.action_apply_failed'),
                            'reload' => t('audit_log.action_reload'),
                            'restart' => t('audit_log.action_restart'),
                            'reload_failed' => t('audit_log.action_reload_failed'),
                            'restart_failed' => t('audit_log.action_restart_failed'),
                        ];
                    ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="u-nowrap u-fs-12 u-muted"><?php echo htmlspecialchars($log['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($log['username'] ?? t('pending_sync.unknown_user')); ?></td>
                            <td>
                                <?php if (!empty($log['domain'])): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($resolveAuditDomainLabel((string)$log['domain'])); ?></span>
                                <?php else: ?>
                                    <span class="u-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo uiStatusBadge($log['action'], $auditActionBadgeMap, 'info', $auditActionLabelMap[$log['action']] ?? t('audit_log.action_' . $log['action'], $log['action'])); ?></td>
                            <td><?php echo htmlspecialchars($log['entity_label']); ?></td>
                            <td class="col-hide-mobile u-mono u-fs-12 u-muted"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-door-open u-primary"></i> <?php echo t('audit_log.header_title_logins'); ?>
        </div>
    </div>

    <form method="GET" autocomplete="off" class="u-d-flex u-gap-12 u-mb-20 u-wrap u-ai-center">
        <input type="hidden" name="date_range" value="<?php echo htmlspecialchars($date_filter); ?>">
        <input type="hidden" name="domain" value="<?php echo htmlspecialchars($domain_filter); ?>">
        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action_filter); ?>">
        <input type="hidden" name="user" value="<?php echo htmlspecialchars($user_filter); ?>">
        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">

        <select name="login_status" class="form-control form-control-sm u-w-auto">
            <option value=""><?php echo t('audit_log.all_statuses'); ?></option>
            <option value="SUCCESS" <?php echo $login_status_filter === 'SUCCESS' ? 'selected' : ''; ?>><?php echo t('audit_log.login_success'); ?></option>
            <option value="FAILED" <?php echo $login_status_filter === 'FAILED' ? 'selected' : ''; ?>><?php echo t('audit_log.login_failed'); ?></option>
            <option value="RESET_REQ" <?php echo $login_status_filter === 'RESET_REQ' ? 'selected' : ''; ?>><?php echo t('audit_log.login_reset_request'); ?></option>
        </select>

        <div class="u-relative u-maxw-220">
            <i class="fas fa-search u-pos-absolute u-left-10 u-top-50pc u-transform-translatey-50pc u-muted u-fs-12 u-pointer-events-none"></i>
            <input type="text" name="login_search" class="form-control form-control-sm u-pl-28" placeholder="<?php echo t('audit_log.login_search_placeholder'); ?>" value="<?php echo htmlspecialchars($login_search_query); ?>">
        </div>

        <button type="submit" class="btn btn-icon btn-primary btn-sm" title="<?php echo t('queue_logs.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
    </form>

    <?php echo uiTableOpen([
        t('audit_log.col_date'),
        t('audit_log.col_username'),
        t('audit_log.col_status'),
        ['label' => t('audit_log.col_ip'), 'class' => 'col-hide-mobile'],
        ['label' => t('audit_log.col_user_agent'), 'class' => 'col-hide-mobile'],
    ]); ?>
                <?php if (empty($login_attempts)): ?>
                    <tr>
                        <td colspan="5" class="u-text-center u-muted u-p-30">
                            <i class="fas fa-search-minus u-fs-32 u-mb-8" style="display: block;"></i>
                            <?php echo t('audit_log.empty'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $loginBadgeMap = ['SUCCESS' => 'success', 'FAILED' => 'danger', 'RESET_REQ' => 'info']; ?>
                    <?php foreach ($login_attempts as $la): ?>
                        <tr>
                            <td class="u-nowrap u-fs-12 u-muted"><?php echo htmlspecialchars($la['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($la['username']); ?></td>
                            <td><?php echo uiStatusBadge($la['status'], $loginBadgeMap, 'info', ['SUCCESS' => t('audit_log.login_success'), 'RESET_REQ' => t('audit_log.login_reset_request')][$la['status']] ?? t('audit_log.login_failed')); ?></td>
                            <td class="col-hide-mobile u-mono u-fs-12 u-muted"><?php echo htmlspecialchars($la['ip_address']); ?></td>
                            <td class="col-hide-mobile u-fs-11 u-muted u-maxw-260 u-of-hidden u-text-overflow-ellipsis u-nowrap" title="<?php echo htmlspecialchars($la['user_agent'] ?? ''); ?>"><?php echo htmlspecialchars($la['user_agent'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>
