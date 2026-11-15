<?php
/** @var string $file @var string $callId @var array $messages */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
// The ends of the dialog, in the order they appear.
$hosts = [];
foreach ($messages as $m) {
    $hosts[$m['src']] ??= count($hosts);
    $hosts[$m['dst']] ??= count($hosts);
}
$colW = 220;
$left = 90;
$rowH = 34;
$top = 46;
$width = $left + max(2, count($hosts)) * $colW;
$height = $top + count($messages) * $rowH + 20;
$x = fn(string $host) => $left + $hosts[$host] * $colW + $colW / 2;
$color = static function (array $m): string {
    if ($m['request']) {
        return 'var(--primary, #2563eb)';
    }
    return $m['status'] < 200 ? 'var(--text-secondary, #64748b)' : ($m['status'] < 300 ? 'var(--success, #16a34a)' : ($m['status'] < 400 ? 'var(--info, #0891b2)' : 'var(--danger, #dc2626)'));
};
$t0 = $messages[0]['ts'] ?? 0;
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-stream', t('siptrace.call_title')); ?>
<?php echo uiPageHeaderActions(); ?>
        <a href="/sip-calls?file=<?php echo $h(urlencode($file)); ?>" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> <?php echo t('siptrace.back_calls'); ?></a>
<?php echo uiPageHeaderClose(); ?>
    <p class="u-fs-12 u-muted u-mono">Call-ID: <?php echo $h($callId); ?> · <?php echo count($messages); ?> <?php echo t('siptrace.col_messages'); ?></p>
    <?php if (!$messages): ?>
        <p class="u-muted"><?php echo t('siptrace.no_messages'); ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <svg class="sip-ladder" width="<?php echo $width; ?>" height="<?php echo $height; ?>" viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" role="img" aria-label="SIP ladder" style="font: 12px var(--font-mono, monospace); color: var(--text-main, #0f172a);">
                <defs>
                    <marker id="arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="context-stroke"/></marker>
                </defs>
                <?php foreach ($hosts as $host => $i): $hx = $left + $i * $colW + $colW / 2; ?>
                    <text x="<?php echo $hx; ?>" y="18" text-anchor="middle" font-weight="700" fill="currentColor"><?php echo $h($host); ?></text>
                    <line x1="<?php echo $hx; ?>" y1="28" x2="<?php echo $hx; ?>" y2="<?php echo $height - 10; ?>" stroke="currentColor" stroke-opacity=".25" stroke-dasharray="4 4"/>
                <?php endforeach; ?>
                <?php foreach ($messages as $n => $m):
                    $y = $top + $n * $rowH + 14;
                    $x1 = $x($m['src']);
                    $x2 = $x($m['dst']);
                    $c = $color($m); ?>
                    <g class="sip-msg" data-n="<?php echo $n; ?>" style="cursor: pointer;">
                        <rect x="0" y="<?php echo $y - 22; ?>" width="<?php echo $width; ?>" height="<?php echo $rowH; ?>" fill="transparent"/>
                        <text x="6" y="<?php echo $y; ?>" fill="currentColor" fill-opacity=".6">+<?php echo $h(number_format($m['ts'] - $t0, 3)); ?></text>
                        <line x1="<?php echo $x1; ?>" y1="<?php echo $y; ?>" x2="<?php echo $x2; ?>" y2="<?php echo $y; ?>" stroke="<?php echo $c; ?>" stroke-width="2" marker-end="url(#arr)"/>
                        <text x="<?php echo ($x1 + $x2) / 2; ?>" y="<?php echo $y - 6; ?>" text-anchor="middle" fill="<?php echo $c; ?>" font-weight="600"><?php echo $h($m['label']); ?></text>
                    </g>
                <?php endforeach; ?>
            </svg>
        </div>
        <div class="u-mt-10">
            <div class="u-fs-12 u-muted" id="sipMsgHead"><?php echo t('siptrace.click_message'); ?></div>
            <pre id="sipMsgText" class="u-mono u-fs-12" style="max-height: 480px; overflow: auto; white-space: pre-wrap; background: var(--bg-hover, #f1f5f9); padding: 12px; border-radius: 6px;"></pre>
        </div>
        <script>
        (function () {
            const msgs = <?php echo json_encode(array_map(fn($m) => ['head' => date('H:i:s', (int) $m['ts']) . sprintf('.%03d', (int) (fmod($m['ts'], 1) * 1000)) . '  ' . $m['src'] . ' → ' . $m['dst'], 'text' => $m['text']], $messages), JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
            const show = n => {
                document.getElementById('sipMsgHead').textContent = msgs[n].head;
                document.getElementById('sipMsgText').textContent = msgs[n].text;
                document.querySelectorAll('.sip-msg').forEach(g => g.style.opacity = g.dataset.n == n ? '1' : '.55');
            };
            document.querySelectorAll('.sip-msg').forEach(g => g.addEventListener('click', () => show(+g.dataset.n)));
            show(0);
        })();
        </script>
    <?php endif; ?>
</div>
