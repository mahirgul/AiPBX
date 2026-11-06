<div class="u-d-flex u-col u-gap-14">
    <div class="card page-header-card u-mb-0">
        <?php echo uiPageHeader('fa-headset', t('cc_agent.title'), 'ccAgentHelpBox'); ?>
        <?php echo uiHelpBoxOpen('ccAgentHelpBox', t('common.module_guide')); ?><?php echo t('cc_agent.help_body'); ?><?php echo uiHelpBoxClose(); ?>
    </div>

    <!-- Agent Queue Status & Multi-Queue Panel -->
    <div class="card u-p-16-20 u-mb-0">
        <div id="agent-queues-list" class="u-d-flex u-wrap u-gap-8">
            <div class="u-muted u-p-4 u-fs-12">
                <?php echo t('cc_agent.queues_loading'); ?>
            </div>
        </div>
    </div>

    <!-- Live Monitor: Queue Waiting Calls & Active Calls -->
    <div class="card u-p-16-20 u-mb-0">
        <div class="u-d-grid u-gtc-1fr u-gap-16">
            <!-- Waiting calls -->
            <div>
                <div class="u-fs-13 u-fw-700 u-warning u-mb-8 u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-clock"></i> <?php echo t('cc_agent.waiting_calls'); ?> <span id="waiting-count-badge" class="badge badge-warning">0</span>
                </div>
                <?php echo uiTableOpen([
                    t('cc_agent.col_queue'),
                    t('cc_agent.col_caller'),
                    t('cc_agent.col_wait'),
                    ['label' => t('cc_agent.col_action'), 'class' => 'u-text-right'],
                ], ['body_id' => 'waiting-calls-tbody', 'search' => false]); ?>
                            <tr>
                                <td colspan="4" class="u-ta-center u-muted u-p-14 u-fs-12">
                                    <?php echo t('cc_agent.no_waiting_calls'); ?>
                                </td>
                            </tr>
                        <?php echo uiTableClose(); ?>
            </div>

            <!-- Active calls -->
            <div>
                <div class="u-fs-13 u-fw-700 u-success u-mb-8 u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-phone-alt"></i> <?php echo t('cc_agent.active_calls'); ?> <span id="active-count-badge" class="badge badge-success">0</span>
                </div>
                <?php echo uiTableOpen([
                    t('cc_agent.col_extension'),
                    t('cc_agent.col_number'),
                    t('cc_agent.col_duration'),
                    t('cc_agent.col_status'),
                    ['label' => t('cc_agent.col_note'), 'class' => 'u-text-right'],
                ], ['body_id' => 'active-calls-tbody', 'search' => false]); ?>
                            <tr>
                                <td colspan="5" class="u-ta-center u-muted u-p-14 u-fs-12">
                                    <?php echo t('cc_agent.no_active_calls'); ?>
                                </td>
                            </tr>
                        <?php echo uiTableClose(); ?>
            </div>
        </div>
    </div>

    <!-- Agent CDR History Card -->
    <div class="card u-p-16-20 u-mb-0">
        <div class="card-header u-mb-12 u-p-0-0-12-16 u-border-bottom-1-solid-border-color">
            <div class="card-title u-fs-14"><i class="fas fa-history u-c-secondary"></i> <?php echo t('cc_agent.recent_calls'); ?></div>
            <button class="btn btn-icon btn-secondary btn-xs" onclick="loadCdrs()" title="<?php echo t('cc_agent.refresh_tooltip'); ?>"><i class="fas fa-sync-alt"></i></button>
        </div>
        <?php echo uiTableOpen([
            t('cc_agent.col_date'),
            t('cc_agent.col_caller'),
            t('cc_agent.col_duration'),
            t('cc_agent.col_status'),
            ['label' => t('cc_agent.col_recording'), 'class' => 'u-text-right'],
            ['label' => t('cc_agent.col_note'), 'class' => 'u-text-right'],
        ], ['body_id' => 'agent-cdr-table', 'search' => false]); ?>
                    <tr>
                        <td colspan="6" class="u-text-center u-muted u-p-20">
                            <?php echo t('cc_agent.loading_calls'); ?>
                        </td>
                    </tr>
                <?php echo uiTableClose(); ?>
    </div>

</div>

<!-- Call note modal -->
<div class="modal-overlay" id="callNoteModal">
    <div class="modal-card u-maxw-480">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-sticky-note u-primary"></i> <?php echo t('cc_agent.note_modal_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeNoteModal()"><i class="fas fa-times"></i></button>
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
        <div class="u-d-flex u-gap-12 u-jc-flex-end u-mt-4 u-card-pad">
            <button type="button" class="btn btn-secondary" onclick="closeNoteModal()"><i class="fas fa-times"></i><span class="btn-label"><?php echo t('cc_agent.cancel'); ?></span></button>
            <button type="button" class="btn btn-primary" onclick="submitCallNote()"><i class="fas fa-save"></i><span class="btn-label"><?php echo t('cc_agent.save'); ?></span></button>
        </div>
    </div>
</div>
