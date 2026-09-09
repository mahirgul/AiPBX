<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-history" style="color: var(--primary);"></i> <?php echo t('fax_sent.header_title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('faxSentHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="faxSentHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fax_sent.help_title'); ?></h4>
        <?php echo t('fax_sent.help_body'); ?>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th><?php echo t('fax_sent.col_sender'); ?></th>
                    <th><?php echo t('fax_sent.col_recipient'); ?></th>
                    <th><?php echo t('fax_sent.col_date'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('fax_sent.col_completed'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('fax_sent.col_pages'); ?></th>
                    <th><?php echo t('fax_sent.col_status'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('fax_sent.col_detail'); ?></th>
                    <th class="text-right"><?php echo t('fax_sent.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sent_faxes)): ?>
                    <?php echo uiTableEmptyRow(9, t('fax_sent.empty'), 'fa-paper-plane'); ?>
                <?php else: ?>
                    <?php foreach ($sent_faxes as $fax): ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $fax['id']; ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($fax['sender_extension']); ?></span></td>
                            <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($fax['destination_number']); ?></td>
                            <td><?php echo date('d.m.Y H:i', strtotime($fax['created_at'])); ?></td>
                            <td class="col-hide-mobile"><?php echo $fax['completed_at'] ? date('d.m.Y H:i', strtotime($fax['completed_at'])) : '-'; ?></td>
                            <td class="col-hide-mobile"><?php echo $fax['pages']; ?> <?php echo t('fax_sent.pages_suffix'); ?></td>
                            <td>
                                <?php echo uiStatusBadge($fax['status'], ['SUCCESS' => 'success', 'FAILED' => 'danger'], 'warning'); ?>
                            </td>
                            <?php
                                // "error_message boşsa -> Sorunsuz iletildi" varsayımı YANLIŞTI:
                                // PENDING durumundaki (henüz sonuçlanmamış) bir faksın da
                                // error_message'ı boş oluyor, ama "sorunsuz iletildi" demek
                                // yanıltıcı (henüz iletilmedi ki) — kullanıcı canlıda fark etti
                                // (2026-08-31). Metin artık status'e göre seçiliyor.
                                if ($fax['error_message']) {
                                    $detail_text = $fax['error_message'];
                                } elseif ($fax['status'] === 'SUCCESS') {
                                    $detail_text = t('fax_sent.delivered_ok');
                                } else {
                                    $detail_text = t('fax_sent.in_progress');
                                }
                            ?>
                            <td class="col-hide-mobile" style="font-size: 12px; color: var(--text-muted);">
                                <span class="cell-truncate" title="<?php echo htmlspecialchars($detail_text); ?>">
                                    <?php echo htmlspecialchars($detail_text); ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <?php if ($fax['pdf_path'] && file_exists($fax['pdf_path'])): ?>
                                        <button class="btn btn-secondary btn-sm" onclick="viewPdf('/api/fax_download.php?id=<?php echo $fax['id']; ?>&type=sent&view=1')" title="<?php echo t('fax_sent.view_tooltip'); ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="/api/fax_download.php?id=<?php echo $fax['id']; ?>&type=sent" class="btn btn-primary btn-sm" target="_blank" title="<?php echo t('fax_sent.download_tooltip'); ?>">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 11px;"><?php echo t('fax_sent.no_file'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($fax['status'] === 'FAILED'): ?>
                                        <form method="POST" autocomplete="off" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(t('fax_sent.resend_confirm'), ENT_QUOTES); ?>');">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="resend_sent_fax" value="1">
                                            <input type="hidden" name="fax_id" value="<?php echo $fax['id']; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="<?php echo t('fax_sent.resend_tooltip'); ?>">
                                                <i class="fas fa-redo"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" autocomplete="off" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(t('fax_sent.delete_confirm'), ENT_QUOTES); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="delete_sent_fax" value="1">
                                        <input type="hidden" name="fax_id" value="<?php echo $fax['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('fax_sent.delete_tooltip'); ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PDF Viewer Modal -->
<div class="modal-overlay" id="pdfModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;"><i class="fas fa-file-pdf" style="color: var(--danger);"></i> <?php echo t('fax_sent.preview_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closePdfModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i> <?php echo t('fax_sent.close'); ?></button>
        </div>
        <div class="modal-body" style="padding: 0; min-height: 500px;">
            <iframe id="pdfFrame" src="" style="width: 100%; height: 550px; border: none;"></iframe>
        </div>
    </div>
</div>
