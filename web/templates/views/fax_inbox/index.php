<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-inbox" style="color: var(--primary);"></i> <?php echo t('fax_inbox.header_title'); ?>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="font-size: 13px; color: var(--text-muted);">
                <?php echo t('fax_inbox.total_prefix'); ?> <?php echo $total_count; ?> <?php echo t('fax_inbox.total_suffix'); ?> <?php if ($total_pages > 1): ?>(<?php echo sprintf(t('fax_inbox.page_of'), $page, $total_pages); ?>)<?php endif; ?>
            </div>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('faxInboxHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="faxInboxHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fax_inbox.help_title'); ?></h4>
        <?php echo t('fax_inbox.help_body'); ?>
    </div>

    <!-- Filter Bar -->
    <form method="GET" autocomplete="off" style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 12px; margin-bottom: 24px; align-items: center;">
        <div style="position: relative;">
            <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; pointer-events: none;"></i>
            <input type="text" name="search" class="form-control form-control-sm" style="padding-left: 32px;" placeholder="<?php echo t('fax_inbox.search_placeholder'); ?>" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> <?php echo t('fax_inbox.filter'); ?></button>
    </form>

    <!-- Fax Data Table -->
    <div class="table-responsive">
        <?php require dirname(__DIR__, 2) . '/pagination_controls.php'; ?>
        <table class="data-table" data-no-dt="true">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th><?php echo t('fax_inbox.col_did'); ?></th>
                    <th><?php echo t('fax_inbox.col_caller'); ?></th>
                    <th><?php echo t('fax_inbox.col_date'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('fax_inbox.col_pages'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('fax_inbox.col_size'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('fax_inbox.col_status'); ?></th>
                    <th class="text-right"><?php echo t('fax_inbox.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($faxes)): ?>
                    <?php echo uiTableEmptyRow(8, t('fax_inbox.empty'), 'fa-inbox'); ?>
                <?php else: ?>
                    <?php foreach ($faxes as $fax): ?>
                        <tr style="<?php echo empty($fax['is_read']) ? 'font-weight: 700;' : ''; ?>">
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $fax['id']; ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($fax['did_extension']); ?></span></td>
                            <td style="font-weight: inherit; color: var(--text-main);">
                                <?php if (empty($fax['is_read'])): ?><i class="fas fa-circle" style="font-size: 6px; color: var(--primary); margin-right: 6px;" title="<?php echo t('fax_inbox.unread_tooltip'); ?>"></i><?php endif; ?>
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
                                <i class="fas fa-envelope<?php echo empty($fax['email_sent']) ? '-open' : ''; ?>" style="margin-left: 4px; color: <?php echo empty($fax['email_sent']) ? 'var(--text-muted)' : 'var(--success)'; ?>; font-size: 11px;" title="<?php echo empty($fax['email_sent']) ? t('fax_inbox.email_not_sent') : t('fax_inbox.email_sent'); ?>"></i>
                            </td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <button class="btn btn-secondary btn-sm" onclick="viewPdf('/api/fax_download.php?id=<?php echo $fax['id']; ?>&view=1')" title="<?php echo t('fax_inbox.view_tooltip'); ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a href="/api/fax_download.php?id=<?php echo $fax['id']; ?>" class="btn btn-primary btn-sm" target="_blank" title="<?php echo t('fax_inbox.download_tooltip'); ?>">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php echo uiDeleteForm($fax['id'], 'fax_id', 'delete_fax', t('fax_inbox.delete_confirm')); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <?php require dirname(__DIR__, 2) . '/pagination.php'; ?>
    <?php endif; ?>
</div>

<!-- PDF Viewer Modal -->
<div class="modal-overlay" id="pdfModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;"><i class="fas fa-file-pdf" style="color: var(--danger);"></i> <?php echo t('fax_inbox.preview_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closePdfModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i> <?php echo t('fax_inbox.close'); ?></button>
        </div>
        <div class="modal-body" style="padding: 0; min-height: 500px;">
            <iframe id="pdfFrame" src="" style="width: 100%; height: 550px; border: none;"></iframe>
        </div>
    </div>
</div>
