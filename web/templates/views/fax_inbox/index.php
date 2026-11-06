<div class="card">
    <?php echo uiPageHeaderOpen('fa-inbox', t('fax_inbox.header_title'), 'faxInboxHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
<div class="u-d-flex u-ai-center u-gap-12">
            <div class="u-fs-13 u-muted">
                <?php echo t('fax_inbox.total_prefix'); ?> <?php echo $total_count; ?> <?php echo t('fax_inbox.total_suffix'); ?> <?php if ($total_pages > 1): ?>(<?php echo sprintf(t('fax_inbox.page_of'), $page, $total_pages); ?>)<?php endif; ?>
            </div>
            
        </div>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('faxInboxHelpBox', t('fax_inbox.help_title')); ?>
        <?php echo t('fax_inbox.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

    <!-- Filter Bar -->
    <form class="u-d-grid u-gtc-2fr-1fr-1fr-auto u-gap-12 u-mb-24 u-ai-center" method="GET" autocomplete="off">
        <div class="u-relative">
            <i class="fas fa-search u-pos-absolute u-left-12 u-top-50pc u-transform-translatey-50pc u-muted u-fs-12 u-pointer-events-none"></i>
            <input type="text" name="search" class="form-control form-control-sm u-pl-32" placeholder="<?php echo t('fax_inbox.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> <?php echo t('fax_inbox.filter'); ?></button>
    </form>

    <!-- Fax Data Table -->
    <div class="table-responsive">
        <?php require dirname(__DIR__, 2) . '/pagination_controls.php'; ?>
        <?php echo uiTableOpen([
            ['label' => '#', 'class' => 'col-hide-mobile u-w-50'],
            t('fax_inbox.col_did'),
            t('fax_inbox.col_caller'),
            t('fax_inbox.col_date'),
            ['label' => t('fax_inbox.col_pages'), 'class' => 'col-hide-mobile'],
            ['label' => t('fax_inbox.col_size'), 'class' => 'col-hide-mobile'],
            ['label' => t('fax_inbox.col_status'), 'class' => 'col-hide-mobile'],
            ['label' => t('fax_inbox.col_actions'), 'class' => 'text-right'],
        ], ['search' => false]); ?>
                <?php if (empty($faxes)): ?>
                    <?php echo uiTableEmptyRow(8, t('fax_inbox.empty'), 'fa-inbox'); ?>
                <?php else: ?>
                    <?php foreach ($faxes as $fax): ?>
                        <tr style="<?php echo empty($fax['is_read']) ? 'font-weight: 700;' : ''; ?>">
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo $fax['id']; ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($fax['did_extension']); ?></span></td>
                            <td class="u-fw-inherit u-text-main">
                                <?php if (empty($fax['is_read'])): ?><i class="fas fa-circle u-fs-6 u-primary u-mr-6" title="<?php echo t('fax_inbox.unread_tooltip'); ?>"></i><?php endif; ?>
                                <?php echo htmlspecialchars($fax['caller_id']); ?>
                            </td>
                            <td><?php echo date('d.m.Y H:i', strtotime($fax['received_at'])); ?></td>
                            <td class="col-hide-mobile"><?php echo $fax['pages']; ?> <?php echo t('fax_inbox.pages_suffix'); ?></td>
                            <td class="col-hide-mobile">
                                <?php
                                    $size_bytes = intval($fax['file_size']);
                                    if ($size_bytes <= 0 && !empty($fax['pdf_path']) && file_exists($fax['pdf_path'])) {
                                        $size_bytes = filesize($fax['pdf_path']);
                                    }
                                    if ($size_bytes >= 1048576) {
                                        echo round($size_bytes / 1048576, 1) . ' MB';
                                    } else {
                                        echo round($size_bytes / 1024, 1) . ' KB';
                                    }
                                ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php echo uiStatusBadge($fax['status'], ['SUCCESS' => 'success'], 'danger'); ?>
                                <?php if (($fax['error_message'] ?? '') !== ''): ?><div class="text-muted u-fs-12"><?php echo htmlspecialchars($fax['error_message']); ?></div><?php endif; ?>
                                <i class="fas fa-envelope<?php echo empty($fax['email_sent']) ? '-open' : ''; ?>" style="margin-left: 4px; color: <?php echo empty($fax['email_sent']) ? 'var(--text-muted)' : 'var(--success)'; ?>; font-size: 11px;" title="<?php echo empty($fax['email_sent']) ? t('fax_inbox.email_not_sent') : t('fax_inbox.email_sent'); ?>"></i>
                            </td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <?php if (($fax['pdf_path'] ?? '') !== ''): // a fax that failed before its first page has no file ?>
                                    <button class="btn btn-icon btn-secondary btn-sm" onclick="viewPdf('/api/fax_download.php?id=<?php echo $fax['id']; ?>&view=1')" title="<?php echo t('fax_inbox.view_tooltip'); ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a href="/api/fax_download.php?id=<?php echo $fax['id']; ?>" class="btn btn-icon btn-primary btn-sm" target="_blank" title="<?php echo t('fax_inbox.download_tooltip'); ?>">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php echo uiDeleteForm($fax['id'], 'fax_id', 'delete_fax', t('fax_inbox.delete_confirm')); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <?php require dirname(__DIR__, 2) . '/pagination.php'; ?>
    <?php endif; ?>
</div>

<!-- PDF Viewer Modal -->
<div class="modal-overlay" id="pdfModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-file-pdf u-danger"></i> <?php echo t('fax_inbox.preview_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="closePdfModal()"><i class="fas fa-times"></i> <?php echo t('fax_inbox.close'); ?></button>
        </div>
        <div class="modal-body u-p-0 u-minh-500">
            <iframe class="u-w-100 u-h-550 u-bd-none" id="pdfFrame" src=""></iframe>
        </div>
    </div>
</div>
