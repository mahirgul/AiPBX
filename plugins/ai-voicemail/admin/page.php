<?php
/**
 * Custom admin panel rendered on `/plugins/ai-voicemail`.
 */
$summaries = PluginManager::kvList('ai-voicemail', 'summaries', 50);
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-voicemail u-primary"></i> AI Voicemail Summaries & Transcripts
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($summaries); ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('common.date', 'Time')); ?></th>
                    <th>Mailbox</th>
                    <th>Caller</th>
                    <th>Duration</th>
                    <th>AI Summary / Transcript</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($summaries)): ?>
                    <?php echo uiTableEmptyRow(5, 'No voicemail messages summarized yet.', 'fa-voicemail'); ?>
                <?php else: ?>
                    <?php foreach ($summaries as $item): ?>
                        <?php $v = is_array($item['value'] ?? null) ? $item['value'] : []; ?>
                        <tr>
                            <td class="u-fs-12"><?php echo htmlspecialchars((string)($v['occurred_at'] ?? $item['updated_at'] ?? '')); ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars((string)($v['mailbox'] ?? '')); ?></span></td>
                            <td><strong><?php echo htmlspecialchars((string)($v['caller'] ?? '')); ?></strong></td>
                            <td class="u-fs-12"><?php echo (int)($v['duration'] ?? 0); ?>s</td>
                            <td>
                                <div class="u-fs-13"><?php echo nl2br(htmlspecialchars((string)($v['summary'] ?? ''))); ?></div>
                                <?php if (!empty($v['transcript'])): ?>
                                    <details class="u-mt-4">
                                        <summary class="text-muted u-fs-11" style="cursor:pointer;">Transcript</summary>
                                        <div class="text-muted u-fs-12 u-mt-4"><?php echo nl2br(htmlspecialchars((string)$v['transcript'])); ?></div>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
