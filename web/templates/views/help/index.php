<?php
/**
 * Admin → Help: document list and search on the left, the document on the right.
 *
 * @var list<array{name: string, title: string}> $docs
 * @var string $current
 * @var string $html  rendered by DocsService (escaped Markdown)
 * @var string $query
 * @var list<array{name: string, title: string, hits: list<array{line: string, anchor: string}>}> $results
 * @var bool $notFound
 */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/help.css'); ?>">
<div class="help-layout">
    <aside class="card help-nav">
        <form method="GET" action="/help" class="help-search">
            <input type="search" name="q" class="form-control" value="<?php echo $h($query); ?>" placeholder="<?php echo $h(t('help.search_placeholder')); ?>" maxlength="100">
        </form>
        <div class="text-muted u-fs-11 help-note"><?php echo t('help.offline_note'); ?></div>
        <nav>
            <?php foreach ($docs as $d): ?>
                <a href="/help?doc=<?php echo $h($d['name']); ?>" class="help-link<?php echo $d['name'] === $current ? ' active' : ''; ?>"><?php echo $h($d['title']); ?></a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <section class="card help-content">
        <?php if ($query !== ''): ?>
            <h2><?php echo $h(sprintf(t('help.results_for'), $query)); ?></h2>
            <?php if ($results === []): ?>
                <p class="text-muted"><?php echo t('help.no_results'); ?></p>
            <?php endif; ?>
            <?php foreach ($results as $r): ?>
                <h3><a href="/help?doc=<?php echo $h($r['name']); ?>"><?php echo $h($r['title']); ?></a></h3>
                <ul class="help-hits">
                    <?php foreach ($r['hits'] as $hit): ?>
                        <li><a href="/help?doc=<?php echo $h($r['name']); ?><?php echo $hit['anchor'] !== '' ? '#' . $h($hit['anchor']) : ''; ?>"><?php echo $h(mb_strimwidth($hit['line'], 0, 220, '…')); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php elseif ($notFound): ?>
            <p class="text-muted"><?php echo t('help.not_found'); ?></p>
        <?php else: ?>
            <article class="doc-body"><?php echo $html; ?></article>
        <?php endif; ?>
    </section>
</div>
