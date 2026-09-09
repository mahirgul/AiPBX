<div style="display: flex; flex-direction: column; gap: 14px;">

    <!-- Agent Queue Status & Multi-Queue Panel -->
    <div class="card" style="padding: 14px 18px; margin-bottom: 0;">
        <div id="agent-queues-list" style="display: flex; flex-wrap: wrap; gap: 8px;">
            <div style="color: var(--text-muted); padding: 4px; font-size: 12px;">
                <?php echo t('cc_agent.queues_loading'); ?>
            </div>
        </div>
    </div>

    <!-- Live Monitor: Queue Waiting Calls & Active Calls -->
    <div class="card" style="padding: 14px 18px; margin-bottom: 0;">
        <div style="display: grid; grid-template-columns: 1fr; gap: 14px;">
            <!-- Bekleyen Çağrılar -->
            <div>
                <div style="font-size: 13px; font-weight: 700; color: var(--warning); margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-clock"></i> <?php echo t('cc_agent.waiting_calls'); ?> <span id="waiting-count-badge" class="badge badge-warning">0</span>
                </div>
                <div class="table-responsive">
                    <table class="data-table" data-no-dt="true">
                        <thead>
                            <tr>
                                <th><?php echo t('cc_agent.col_queue'); ?></th>
                                <th><?php echo t('cc_agent.col_caller'); ?></th>
                                <th><?php echo t('cc_agent.col_wait'); ?></th>
                                <th style="text-align: right;"><?php echo t('cc_agent.col_action'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="waiting-calls-tbody">
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 14px; font-size: 12px;">
                                    <?php echo t('cc_agent.no_waiting_calls'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Aktif Görüşmeler -->
            <div>
                <div style="font-size: 13px; font-weight: 700; color: var(--success); margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-phone-alt"></i> <?php echo t('cc_agent.active_calls'); ?> <span id="active-count-badge" class="badge badge-success">0</span>
                </div>
                <div class="table-responsive">
                    <table class="data-table" data-no-dt="true">
                        <thead>
                            <tr>
                                <th><?php echo t('cc_agent.col_extension'); ?></th>
                                <th><?php echo t('cc_agent.col_number'); ?></th>
                                <th><?php echo t('cc_agent.col_duration'); ?></th>
                                <th><?php echo t('cc_agent.col_status'); ?></th>
                                <th style="text-align: right;"><?php echo t('cc_agent.col_note'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="active-calls-tbody">
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 14px; font-size: 12px;">
                                    <?php echo t('cc_agent.no_active_calls'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Agent CDR History Card -->
    <div class="card" style="padding: 14px 18px; margin-bottom: 0;">
        <div class="card-header" style="margin-bottom: 10px; padding: 0 0 10px 0; border-bottom: 1px solid var(--border-color);">
            <div class="card-title" style="font-size: 14px;"><i class="fas fa-history" style="color: var(--secondary);"></i> <?php echo t('cc_agent.recent_calls'); ?></div>
            <button class="btn btn-secondary btn-xs" onclick="loadCdrs()" title="<?php echo t('cc_agent.refresh_tooltip'); ?>"><i class="fas fa-sync-alt"></i></button>
        </div>
        <div class="table-responsive">
            <table class="data-table" data-no-dt="true">
                <thead>
                    <tr>
                        <th><?php echo t('cc_agent.col_date'); ?></th>
                        <th><?php echo t('cc_agent.col_caller'); ?></th>
                        <th><?php echo t('cc_agent.col_duration'); ?></th>
                        <th><?php echo t('cc_agent.col_status'); ?></th>
                        <th style="text-align: right;"><?php echo t('cc_agent.col_recording'); ?></th>
                        <th style="text-align: right;"><?php echo t('cc_agent.col_note'); ?></th>
                    </tr>
                </thead>
                <tbody id="agent-cdr-table">
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">
                            <?php echo t('cc_agent.loading_calls'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Çağrı Notu Modal -->
<div class="modal-overlay" id="callNoteModal">
    <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;"><i class="fas fa-sticky-note" style="color: var(--primary);"></i> <?php echo t('cc_agent.note_modal_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closeNoteModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="note_call_id" value="">
            <div class="form-group">
                <label class="form-label"><?php echo t('cc_agent.field_customer_name'); ?></label>
                <input type="text" id="note_customer_name" class="form-control" placeholder="<?php echo t('cc_agent.field_customer_name_placeholder'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('cc_agent.field_phone'); ?></label>
                <input type="text" id="note_phone" class="form-control" placeholder="<?php echo t('cc_agent.field_phone_placeholder'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('cc_agent.field_disposition'); ?></label>
                <input type="text" id="note_disposition" class="form-control" placeholder="<?php echo t('cc_agent.field_disposition_placeholder'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('cc_agent.field_note'); ?></label>
                <textarea id="note_notes" class="form-control" rows="4" placeholder="<?php echo t('cc_agent.field_note_placeholder'); ?>"></textarea>
            </div>
        </div>
        <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 4px; padding: 0 20px 20px;">
            <button type="button" class="btn btn-secondary" onclick="closeNoteModal()"><i class="fas fa-times"></i><span class="btn-label"><?php echo t('cc_agent.cancel'); ?></span></button>
            <button type="button" class="btn btn-primary" onclick="submitCallNote()"><i class="fas fa-save"></i><span class="btn-label"><?php echo t('cc_agent.save'); ?></span></button>
        </div>
    </div>
</div>
