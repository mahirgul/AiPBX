<?php

/**
 * Server-side paging — the same look as the DataTable view used across the system.
 *
 * Why separate: assets/js/datatable_enhancer.js pages every table on the
 * client side, but that needs ALL rows loaded into the browser. The call
 * reports and the fax inbox grow to thousands of rows, so that path slowed the
 * page down; these two tables opt out with `data-no-dt="true"` and the server
 * does the paging.
 *
 * It should not be noticeable visually: the same classes (dt-pagination-bar,
 * dt-info, dt-pagination-nav, dt-page-btn) and the same visual language are
 * used.
 *
 * Expected variables:
 *   $page        — current page (starts at 1)
 *   $total_pages — total number of pages
 * Optional:
 *   $total_rows  — total records (for the info line)
 *   $page_size   — page size (computes the range in the info line)
 *
 * The "Show: N records" selector sits separately ABOVE the table:
 * templates/pagination_controls.php
 */

$page = max(1, intval($page ?? 1));
$total_pages = max(1, intval($total_pages ?? 1));
$page_size = intval($page_size ?? 0);
$total_rows = intval($total_rows ?? 0);

// Hide when there are no records, or a single page with an unknown record count
if ($total_rows <= 0 && $total_pages <= 1) {
    return;
}

$sayfa_url = static function (int $n): string {
    $qs = $_GET;
    $qs['page'] = $n;
    return '?' . http_build_query($qs);
};


$ilk = max(1, $page - 2);
$son = min($total_pages, $ilk + 4);
$ilk = max(1, $son - 4);
?>
<div class="dt-pagination-bar">

        <div class="dt-info">
            <?php
            if (!empty($total_rows) && $page_size > 0) {
                $bas = (($page - 1) * $page_size) + 1;
                $bit = min($page * $page_size, (int) $total_rows);
                echo htmlspecialchars(sprintf(t('pagination.range'), $bas, $bit, $total_rows));
            } else {
                echo htmlspecialchars(sprintf(t('pagination.page_of'), $page, $total_pages));
            }
            ?>
        </div>

    <div class="dt-pagination-nav">
        <?php if ($total_pages > 1): ?>
            <a class="dt-page-btn" href="<?php echo htmlspecialchars($sayfa_url(max(1, $page - 1))); ?>"
               <?php echo $page <= 1 ? 'aria-disabled="true"' : ''; ?>>
                <i class="fas fa-chevron-left"></i>
            </a>

            <?php for ($n = $ilk; $n <= $son; $n++): ?>
                <a class="dt-page-btn<?php echo $n === $page ? ' active' : ''; ?>"
                   href="<?php echo htmlspecialchars($sayfa_url($n)); ?>"><?php echo $n; ?></a>
            <?php endfor; ?>

            <a class="dt-page-btn" href="<?php echo htmlspecialchars($sayfa_url(min($total_pages, $page + 1))); ?>"
               <?php echo $page >= $total_pages ? 'aria-disabled="true"' : ''; ?>>
                <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
</div>
