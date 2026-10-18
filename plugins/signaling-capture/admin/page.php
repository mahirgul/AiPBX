<?php
/**
 * Custom telemetry status panel rendered on `/plugins/signaling-capture`.
 */
$hepStatusRaw = '';
if (class_exists('AsteriskHelper')) {
    $hepStatusRaw = trim((string)AsteriskHelper::execCLI('hep show status'));
}
$recentEvents = PluginManager::kvList('signaling-capture', 'recent_events', 25);
?>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-terminal u-info"></i> Asterisk HEPv3 Status (<code>hep show status</code>)
        </div>
    </div>
    <div style="padding: 16px 20px;">
        <pre style="margin: 0; padding: 12px; border-radius: 6px; background: var(--bg-hover, rgba(15,23,42,0.06)); font-size: 12px; overflow-x: auto;"><?php echo htmlspecialchars($hepStatusRaw !== '' ? $hepStatusRaw : 'res_hep module not loaded or no active capture server.'); ?></pre>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-wave-square u-primary"></i> Recent Captured Signaling Events
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($recentEvents); ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>Signaling Payload</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentEvents)): ?>
                    <?php echo uiTableEmptyRow(3, 'No signaling events captured yet.', 'fa-satellite-dish'); ?>
                <?php else: ?>
                    <?php foreach ($recentEvents as $item): ?>
                        <?php $val = is_array($item['value'] ?? null) ? $item['value'] : []; ?>
                        <tr>
                            <td class="u-fs-12"><?php echo htmlspecialchars((string)($val['occurred_at'] ?? $item['updated_at'] ?? '')); ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars((string)($val['event'] ?? '')); ?></span></td>
                            <td><code class="u-fs-11"><?php echo htmlspecialchars((string)json_encode($val['data'] ?? [], JSON_UNESCAPED_UNICODE)); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
