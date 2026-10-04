<?php
// PDF / Excel download of the report as currently filtered (ReportExport).
// The page's controller answers ?export=pdf|xlsx with the same filters.
$__exportUrl = function (string $format) {
    $q = $_GET;
    unset($q['page']);
    $q['export'] = $format;
    return strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($q);
};
?>
<a href="<?php echo htmlspecialchars($__exportUrl('pdf')); ?>" class="btn btn-secondary btn-sm" title="<?php echo htmlspecialchars(t('export.pdf_tooltip')); ?>"><i class="fas fa-file-pdf" style="color: #dc2626;"></i> <?php echo t('export.btn_pdf'); ?></a>
<a href="<?php echo htmlspecialchars($__exportUrl('xlsx')); ?>" class="btn btn-secondary btn-sm" title="<?php echo htmlspecialchars(t('export.xlsx_tooltip')); ?>"><i class="fas fa-file-excel" style="color: #16a34a;"></i> <?php echo t('export.btn_xlsx'); ?></a>
