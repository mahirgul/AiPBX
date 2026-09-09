<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-shield-alt" style="color: var(--primary);"></i> <?php echo t('audit_log.header_title_pbx'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('auditHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>
    </div>

    <div class="module-help-box" id="auditHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('audit_log.help_title'); ?></h4>
        <?php echo t('audit_log.help_body'); ?>
    </div>

    <form method="GET" autocomplete="off" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
        <input type="hidden" name="login_status" value="<?php echo htmlspecialchars($login_status_filter); ?>">
        <input type="hidden" name="login_search" value="<?php echo htmlspecialchars($login_search_query); ?>">
        <select name="date_range" class="form-control form-control-sm" style="width: auto;">
            <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_today'); ?></option>
            <option value="yesterday" <?php echo $date_filter === 'yesterday' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_yesterday'); ?></option>
            <option value="week" <?php echo $date_filter === 'week' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_week'); ?></option>
            <option value="month" <?php echo $date_filter === 'month' ? 'selected' : ''; ?>><?php echo t('queue_logs.range_month'); ?></option>
            <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>><?php echo t('audit_log.range_all'); ?></option>
        </select>

        <select name="domain" class="form-control form-control-sm" style="width: auto;">
            <option value=""><?php echo t('audit_log.all_domains'); ?></option>
            <?php foreach (array_keys($domain_map) as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $domain_filter === $d ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(t('pending_sync.domain_' . $d, $d)); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="action" class="form-control form-control-sm" style="width: auto;">
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

        <select name="user" class="form-control form-control-sm" style="width: auto;">
            <option value=""><?php echo t('audit_log.all_users'); ?></option>
            <?php foreach ($user_map as $uid => $uname): ?>
                <option value="<?php echo htmlspecialchars((string)$uid); ?>" <?php echo $user_filter === (string)$uid ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($uname); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div style="position: relative; max-width: 220px;">
            <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; pointer-events: none;"></i>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="<?php echo t('audit_log.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 28px;">
        </div>

        <button type="submit" class="btn btn-primary btn-sm" title="<?php echo t('queue_logs.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
    </form>

    <div class="table-responsive">
        <table class="data-table" data-no-dt="true">
            <thead>
                <tr>
                    <th><?php echo t('audit_log.col_date'); ?></th>
                    <th><?php echo t('audit_log.col_user'); ?></th>
                    <th><?php echo t('audit_log.col_domain'); ?></th>
                    <th><?php echo t('audit_log.col_action'); ?></th>
                    <th><?php echo t('audit_log.col_change'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('audit_log.col_ip'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            <i class="fas fa-search-minus" style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
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
                            <td style="white-space: nowrap; font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($log['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($log['username'] ?? t('pending_sync.unknown_user')); ?></td>
                            <td>
                                <?php if (!empty($log['domain'])): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars(t('pending_sync.domain_' . $log['domain'], $log['domain'])); ?></span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo uiStatusBadge($log['action'], $auditActionBadgeMap, 'info', $auditActionLabelMap[$log['action']] ?? $log['action']); ?></td>
                            <td><?php echo htmlspecialchars($log['entity_label']); ?></td>
                            <td class="col-hide-mobile" style="font-family: monospace; font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-door-open" style="color: var(--primary);"></i> <?php echo t('audit_log.header_title_logins'); ?>
        </div>
    </div>

    <form method="GET" autocomplete="off" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
        <input type="hidden" name="date_range" value="<?php echo htmlspecialchars($date_filter); ?>">
        <input type="hidden" name="domain" value="<?php echo htmlspecialchars($domain_filter); ?>">
        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action_filter); ?>">
        <input type="hidden" name="user" value="<?php echo htmlspecialchars($user_filter); ?>">
        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">

        <select name="login_status" class="form-control form-control-sm" style="width: auto;">
            <option value=""><?php echo t('audit_log.all_statuses'); ?></option>
            <option value="SUCCESS" <?php echo $login_status_filter === 'SUCCESS' ? 'selected' : ''; ?>><?php echo t('audit_log.login_success'); ?></option>
            <option value="FAILED" <?php echo $login_status_filter === 'FAILED' ? 'selected' : ''; ?>><?php echo t('audit_log.login_failed'); ?></option>
        </select>

        <div style="position: relative; max-width: 220px;">
            <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; pointer-events: none;"></i>
            <input type="text" name="login_search" class="form-control form-control-sm" placeholder="<?php echo t('audit_log.login_search_placeholder'); ?>" value="<?php echo htmlspecialchars($login_search_query); ?>" style="padding-left: 28px;">
        </div>

        <button type="submit" class="btn btn-primary btn-sm" title="<?php echo t('queue_logs.filter_tooltip'); ?>"><i class="fas fa-filter"></i></button>
    </form>

    <div class="table-responsive">
        <table class="data-table" data-no-dt="true">
            <thead>
                <tr>
                    <th><?php echo t('audit_log.col_date'); ?></th>
                    <th><?php echo t('audit_log.col_username'); ?></th>
                    <th><?php echo t('audit_log.col_status'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('audit_log.col_ip'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('audit_log.col_user_agent'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($login_attempts)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            <i class="fas fa-search-minus" style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
                            <?php echo t('audit_log.empty'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $loginBadgeMap = ['SUCCESS' => 'success', 'FAILED' => 'danger']; ?>
                    <?php foreach ($login_attempts as $la): ?>
                        <tr>
                            <td style="white-space: nowrap; font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($la['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($la['username']); ?></td>
                            <td><?php echo uiStatusBadge($la['status'], $loginBadgeMap, 'info', $la['status'] === 'SUCCESS' ? t('audit_log.login_success') : t('audit_log.login_failed')); ?></td>
                            <td class="col-hide-mobile" style="font-family: monospace; font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($la['ip_address']); ?></td>
                            <td class="col-hide-mobile" style="font-size: 11px; color: var(--text-muted); max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($la['user_agent'] ?? ''); ?>"><?php echo htmlspecialchars($la['user_agent'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
