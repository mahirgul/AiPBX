<?php
/**
 * Admin -> Outbound Webhooks (`/webhooks`)
 *
 * @var list<array<string, mixed>> $webhooks
 * @var array<string, string> $supported_events
 * @var list<array<string, mixed>> $recent_deliveries
 * @var string $csrf_token
 */
?>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-bolt u-primary"></i> <?php echo htmlspecialchars(t('webhooks.title', 'Outbound Webhooks')); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($webhooks); ?></span>
        </div>
        <div class="u-flex-center" style="gap: 8px;">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="process_queue_now" value="1">
                <button type="submit" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sync-alt"></i> <?php echo htmlspecialchars(t('webhooks.btn_flush_queue', 'Process Pending Queue Now')); ?>
                </button>
            </form>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('createWebhookBox').style.display = document.getElementById('createWebhookBox').style.display === 'none' ? 'block' : 'none';">
                <i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars(t('webhooks.btn_new', 'Add Webhook')); ?>
            </button>
        </div>
    </div>

    <div id="createWebhookBox" style="display: none; padding: 20px; border-bottom: 1px solid var(--border-color); background: var(--bg-subtle, rgba(2,132,199,0.04));">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="save_webhook" value="1">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;"><?php echo htmlspecialchars(t('webhooks.field_name', 'Webhook Name')); ?></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. CRM Call Ended Hook" required>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;"><?php echo htmlspecialchars(t('webhooks.field_url', 'Target URL (HTTPS/HTTP)')); ?></label>
                    <input type="url" name="target_url" class="form-control" placeholder="https://crm.example.com/webhooks/aipbx" required>
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;"><?php echo htmlspecialchars(t('webhooks.field_secret', 'Signing Secret (HMAC-SHA256)')); ?></label>
                    <input type="text" name="secret" class="form-control" placeholder="Leave blank to auto-generate whsec_...">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;"><?php echo htmlspecialchars(t('webhooks.field_retries', 'Max Retries')); ?></label>
                    <input type="number" name="max_retries" class="form-control" value="5" min="1" max="10">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label" style="font-weight: 600; display: block; margin-bottom: 8px;">
                    <?php echo htmlspecialchars(t('webhooks.field_events', 'Subscribed Events')); ?>
                </label>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 8px;">
                    <?php foreach ($supported_events as $evKey => $evDesc): ?>
                        <label style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 6px; cursor: pointer; font-size: 12px;">
                            <input type="checkbox" name="events[]" value="<?php echo htmlspecialchars($evKey); ?>">
                            <span><code><?php echo htmlspecialchars($evKey); ?></code> — <?php echo htmlspecialchars($evDesc); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('webhooks.btn_save', 'Save Webhook')); ?>
                </button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('webhooks.col_name', 'Webhook & Target URL')); ?></th>
                    <th><?php echo htmlspecialchars(t('webhooks.col_events', 'Subscribed Events')); ?></th>
                    <th><?php echo htmlspecialchars(t('webhooks.col_secret', 'HMAC Secret')); ?></th>
                    <th><?php echo htmlspecialchars(t('webhooks.col_last_delivery', 'Last Delivery')); ?></th>
                    <th class="text-right"><?php echo htmlspecialchars(t('common.actions', 'Actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($webhooks)): ?>
                    <?php echo uiTableEmptyRow(5, t('webhooks.empty', 'No outbound webhooks configured yet.'), 'fa-bolt'); ?>
                <?php else: ?>
                    <?php foreach ($webhooks as $wh): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$wh['name']); ?></strong>
                                <div class="text-muted u-fs-11"><code><?php echo htmlspecialchars((string)$wh['target_url']); ?></code></div>
                            </td>
                            <td>
                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    <?php foreach ($wh['events'] as $ev): ?>
                                        <span class="badge badge-info u-fs-11"><?php echo htmlspecialchars((string)$ev); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars((string)($wh['secret_masked'] ?: '—')); ?></code>
                            </td>
                            <td>
                                <?php if (!empty($wh['last_triggered_at'])): ?>
                                    <?php $okCode = (int)($wh['last_status_code'] ?? 0); ?>
                                    <span class="badge <?php echo ($okCode >= 200 && $okCode < 300) ? 'badge-success' : 'badge-danger'; ?>">
                                        HTTP <?php echo $okCode; ?>
                                    </span>
                                    <span class="text-muted u-fs-11"><?php echo htmlspecialchars((string)$wh['last_triggered_at']); ?></span>
                                    <?php if (!empty($wh['last_error'])): ?>
                                        <div class="text-danger u-fs-11"><?php echo htmlspecialchars((string)$wh['last_error']); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted u-fs-12">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="test_webhook_id" value="<?php echo (int)$wh['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo htmlspecialchars(t('webhooks.btn_test', 'Send Test Ping')); ?>">
                                        <i class="fas fa-paper-plane"></i> <?php echo htmlspecialchars(t('webhooks.btn_test', 'Test')); ?>
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm(<?php echo jsArg(t('webhooks.confirm_delete', 'Delete this webhook?')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="delete_webhook_id" value="<?php echo (int)$wh['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
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
            <i class="fas fa-stream u-info"></i> <?php echo htmlspecialchars(t('webhooks.queue_title', 'Recent Event Delivery Queue')); ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('webhooks.col_event', 'Event')); ?></th>
                    <th><?php echo htmlspecialchars(t('webhooks.col_target', 'Target')); ?></th>
                    <th><?php echo htmlspecialchars(t('common.status', 'Status')); ?></th>
                    <th><?php echo htmlspecialchars(t('webhooks.col_attempts', 'Attempts')); ?></th>
                    <th><?php echo htmlspecialchars(t('webhooks.col_created', 'Queued At')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_deliveries)): ?>
                    <?php echo uiTableEmptyRow(5, t('webhooks.queue_empty', 'No recent event deliveries.'), 'fa-stream'); ?>
                <?php else: ?>
                    <?php foreach ($recent_deliveries as $q): ?>
                        <tr>
                            <td>
                                <code><?php echo htmlspecialchars((string)$q['event_name']); ?></code>
                                <div class="text-muted u-fs-11"><?php echo htmlspecialchars((string)$q['event_id']); ?></div>
                            </td>
                            <td>
                                <?php if (!empty($q['plugin_slug'])): ?>
                                    <span class="badge badge-info">plugin:<?php echo htmlspecialchars((string)$q['plugin_slug']); ?></span>
                                <?php else: ?>
                                    <strong><?php echo htmlspecialchars((string)($q['webhook_name'] ?? ('#' . $q['webhook_id']))); ?></strong>
                                    <div class="text-muted u-fs-11"><?php echo htmlspecialchars((string)($q['target_url'] ?? '')); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $st = (string)$q['status'];
                                $badge = $st === 'delivered' ? 'badge-success' : ($st === 'failed' ? 'badge-danger' : 'badge-warning');
                                ?>
                                <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($st); ?></span>
                                <?php if (!empty($q['last_http_code'])): ?>
                                    <span class="badge badge-secondary u-fs-11">HTTP <?php echo (int)$q['last_http_code']; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($q['last_error'])): ?>
                                    <div class="text-danger u-fs-11"><?php echo htmlspecialchars((string)$q['last_error']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$q['attempts']; ?> / <?php echo (int)$q['max_attempts']; ?></td>
                            <td class="u-fs-12"><?php echo htmlspecialchars((string)$q['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
