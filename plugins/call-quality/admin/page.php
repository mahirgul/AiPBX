<?php
/**
 * Call quality panel on `/plugins/call-quality`.
 *
 * Read-only on purpose. Everything that changes something — a trunk demotion —
 * happens in the cron, is written to the audit log and is undone on the
 * Outbound routes page; a page that could also reorder routes would be a second
 * way to make the same change, with its own bugs.
 *
 * The first question an install that "does nothing" has is whether RTCP even
 * arrives here, so capture state comes before the numbers.
 *
 * @var array<string, mixed> $plugin
 * @var array<string, mixed> $settings
 */

require_once __DIR__ . '/../web/src/services/CaptureState.php';
require_once __DIR__ . '/../web/src/services/CallQualityService.php';

$capture = CaptureState::snapshot();
$summary = $capture['local'] ? CallQualityService::summary() : null;
$evidence = CallQualityService::recentEvidence(24, 20);
$autoFix = CallQualityService::autoFix(is_array($settings ?? null) ? $settings : []);

$captureBadge = $capture['local'] ? 'badge-success' : 'badge-warning';
$captureLabel = $capture['local'] ? 'RTCP arrives here' : 'No local RTCP rows';

$levelBadge = static fn(string $level): string => match ($level) {
    'poor' => 'badge-danger',
    'fair' => 'badge-warning',
    'good' => 'badge-success',
    default => 'badge-secondary',
};
?>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-satellite-dish u-info"></i> Capture state
            <span class="badge <?php echo $captureBadge; ?> u-fs-11 u-ml-8"><?php echo htmlspecialchars($captureLabel); ?></span>
        </div>
    </div>
    <div style="padding: 16px 20px;">
        <p style="margin: 0 0 10px;"><?php echo htmlspecialchars($capture['detail']); ?></p>
        <table class="data-table">
            <tbody>
                <tr>
                    <th style="width: 180px;">Mode</th>
                    <td><code><?php echo htmlspecialchars($capture['mode']); ?></code></td>
                </tr>
                <tr>
                    <th>Asterisk sends HEP to</th>
                    <td><code><?php echo htmlspecialchars($capture['address'] !== '' ? $capture['address'] : '(not set)'); ?></code></td>
                </tr>
                <tr>
                    <th>aipbx-signaling listens on</th>
                    <td><code><?php echo htmlspecialchars($capture['listener']); ?></code></td>
                </tr>
                <tr>
                    <th>Configuration</th>
                    <td><code><?php echo htmlspecialchars($capture['path']); ?></code><?php if ($capture['includes']): ?>
                        <span class="u-fs-12" style="color: var(--text-muted);"> (includes <?php echo htmlspecialchars(implode(', ', $capture['includes'])); ?>)</span><?php endif; ?></td>
                </tr>
                <tr>
                    <th>Automatic trunk demotion</th>
                    <td><?php echo $autoFix
                        ? '<span class="badge badge-warning u-fs-11">on — new calls only, audited</span>'
                        : '<span class="badge badge-secondary u-fs-11">off — evidence and notices only</span>'; ?></td>
                </tr>
            </tbody>
        </table>
        <?php if ($summary !== null): ?>
            <p style="margin: 12px 0 0; font-size: 13px; color: var(--text-muted);">
                Last <?php echo (int) CallQualityService::WINDOW_MIN; ?> min:
                <?php echo (int) $summary['measured']; ?> call(s) with RTCP reports —
                <?php echo (int) $summary['poor']; ?> poor,
                <?php echo (int) $summary['fair']; ?> with some loss,
                <?php echo (int) $summary['good']; ?> good.
            </p>
        <?php endif; ?>
        <?php if (!$capture['local']): ?>
            <p style="margin: 10px 0 0; font-size: 13px;">
                Nothing is scored until Asterisk's <code>hep.conf</code> points at this server's daemon
                (`enabled = yes`, `capture_address = 127.0.0.1:9061`). A remote HOMER or VoIPmonitor
                collector is a valid choice — it just keeps the reports there, not here.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-clipboard-check u-primary"></i> Evidence, last 24 h
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($evidence); ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Recorded</th>
                    <th>Level</th>
                    <th>Call</th>
                    <th>Trunk</th>
                    <th>Peer</th>
                    <th>Worst loss</th>
                    <th>Reports</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($evidence === []): ?>
                    <?php echo uiTableEmptyRow(9, 'No quality incident recorded in the last 24 hours.', 'fa-chart-line'); ?>
                <?php else: ?>
                    <?php foreach ($evidence as $row): ?>
                        <tr>
                            <td class="u-fs-12"><?php echo (int) $row['id']; ?></td>
                            <td class="u-fs-12"><?php echo htmlspecialchars((string) $row['created_at']); ?></td>
                            <td><span class="badge <?php echo $levelBadge((string) $row['level']); ?> u-fs-11"><?php echo htmlspecialchars((string) $row['level']); ?></span></td>
                            <td><code class="u-fs-11"><?php echo htmlspecialchars(mb_substr((string) $row['call_id'], 0, 32)); ?></code></td>
                            <td class="u-fs-12"><?php echo htmlspecialchars((string) $row['trunk_name'] !== '' ? (string) $row['trunk_name'] : '—'); ?></td>
                            <td><code class="u-fs-11"><?php echo htmlspecialchars((string) $row['peer']); ?></code></td>
                            <td class="u-fs-12"><?php echo htmlspecialchars(sprintf('%.2f%%', (float) $row['loss_pct'])); ?></td>
                            <td class="u-fs-12"><?php echo (int) $row['reports']; ?></td>
                            <td class="u-fs-11"><?php echo htmlspecialchars((string) $row['action_detail'] !== '' ? (string) $row['action'] : 'no action'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="padding: 12px 20px; font-size: 12px; color: var(--text-muted);">
        Full trail of one incident:
        <code>aipbx_plugin_cli.php call-quality evidence &lt;id&gt;</code> ·
        a day of incidents:
        <code>aipbx_plugin_cli.php call-quality report</code> ·
        one pass now:
        <code>aipbx_plugin_cli.php call-quality tick</code>
    </div>
</div>
