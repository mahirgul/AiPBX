<?php

/**
 * Paging controls ABOVE the table — the "Show: N records" selector.
 *
 * Same layout as the client-side enhancer produces
 * (assets/js/datatable_enhancer.js): `dt-controls-bar` above the table, the
 * selector on the RIGHT. Users are used to it on the other pages, so it must
 * sit in the same place with server-side paging too.
 *
 * There is NO search box here: these pages have their own filter form; a
 * second search field would look like two different filters.
 *
 * Expected variable:
 *   $page_size — current page size
 */

$page_size = intval($page_size ?? 0);
if ($page_size <= 0) {
    return;
}

// Changing the size goes back to page 1: going up to 100 while on page 5,
// that page might not exist and the list would look empty.
$boyut_url = static function (int $b): string {
    $qs = $_GET;
    $qs['boyut'] = $b;
    $qs['page'] = 1;
    return '?' . http_build_query($qs);
};
?>
<div class="dt-controls-bar">
    <div></div>
    <div class="dt-length-box">
        <span><?php echo t('pagination.show'); ?></span>
        <select class="dt-length-select" onchange="if(window.loadSPAPage){window.loadSPAPage(this.value,true);}else{window.location.href=this.value;}">
            <?php foreach (View::SAYFA_BOYUTLARI as $b): ?>
                <option value="<?php echo htmlspecialchars($boyut_url($b)); ?>" <?php echo $b === $page_size ? 'selected' : ''; ?>>
                    <?php echo sprintf(t('pagination.records'), $b); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
