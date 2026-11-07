<div class="u-d-flex u-col u-gap-20" id="cc-board-root">

    <!-- Top bar: queue/date filter & full screen -->
    <div class="card page-header-card u-mb-0">
        <?php echo uiPageHeaderOpen('fa-chart-line', t('sidebar.item_cc_board_unified'), 'ccBoardHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
<select id="board-queue-filter" class="form-control form-control-sm u-w-190 u-fw-600" onchange="loadAllBoardData()">
                    <option value="ALL">🌐 <?php echo t('cc_board.all_my_queues'); ?> (<?php echo count($my_queues); ?>)</option>
                    <?php foreach ($my_queues as $q): ?>
                        <option value="<?php echo htmlspecialchars($q['queue_name']); ?>">
                            🎧 <?php echo htmlspecialchars($q['title'] ?: $q['queue_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="board-range-filter" class="form-control form-control-sm u-w-120 u-fw-600" onchange="loadAllBoardData()">
                    <option value="today"><?php echo t('cc_board.today'); ?></option>
                    <option value="week"><?php echo t('cc_board.this_week'); ?></option>
                    <option value="month"><?php echo t('cc_board.this_month'); ?></option>
                </select>

                <div class="u-d-flex u-ai-center u-gap-8 u-p-4-12 u-bg-rgba-0-0-0-0-04 u-r-8 u-fw-700">
                    <i class="far fa-clock u-primary"></i>
                    <span class="u-mono u-fs-13 u-text-main" id="board-clock">--:--</span>
                    <span class="u-fs-11 u-muted u-uppercase" id="board-day">-</span>
                </div>

                <button class="btn btn-icon btn-secondary btn-sm" onclick="toggleBoardFullscreen()" title="<?php echo t('cc_board.fullscreen_tooltip'); ?>">
                    <i class="fas fa-expand" id="board-fullscreen-icon"></i>
                </button>
                <button class="btn btn-primary btn-sm" onclick="loadAllBoardData()" title="<?php echo t('cc_board.refresh_tooltip'); ?>">
                    <i class="fas fa-sync-alt" id="board-refresh-icon"></i> <?php echo t('cc_board.refresh'); ?>
                </button>
<?php echo uiPageHeaderClose(); ?>
<?php echo uiHelpBoxOpen('ccBoardHelpBox', t('common.module_guide')); ?><?php echo t('cc_board.subtitle'); ?><?php echo uiHelpBoxClose(); ?>
    </div>

    <!-- SECTION 1: compact live board and KPI metrics -->
    <div class="cc-board-kpi-grid u-d-grid u-gtc-repeat-auto-fit-minmax-190-1fr u-gap-12">
        <!-- 1. Waiting calls -->
        <div class="card u-p-12-16 u-border-left-4-solid-danger u-d-flex u-col u-jc-space-between">
            <div class="u-flex-between">
                <span class="cc-board-label u-danger"><?php echo t('cc_board.waiting_call'); ?></span>
                <i class="fas fa-phone-volume u-danger u-opacity-0-7 u-fs-14"></i>
            </div>
            <div class="u-d-flex u-ai-baseline u-gap-8 u-mt-4">
                <div class="cc-board-val-compact u-danger" id="board-waiting-calls">0</div>
                <span class="u-fs-11 u-muted u-ml-auto"><?php echo t('cc_board.max'); ?>: <span class="u-mono u-fw-700 u-text-main" id="board-max-wait">00:00</span></span>
            </div>
        </div>

        <!-- 2. Answered / total calls -->
        <div class="card u-p-12-16 u-border-left-4-solid-secondary u-d-flex u-col u-jc-space-between">
            <div class="u-flex-between">
                <span class="cc-board-label"><?php echo t('cc_board.answered_call'); ?> / <?php echo t('cc_board.total_call'); ?></span>
                <i class="fas fa-phone-alt u-c-secondary u-opacity-0-7 u-fs-14"></i>
            </div>
            <div class="u-d-flex u-ai-baseline u-gap-6 u-mt-4">
                <span class="cc-board-val-compact u-c-secondary" id="board-answered-calls">0</span>
                <span class="u-fs-13 u-muted u-fw-700">/ <span id="board-total-calls">0</span></span>
                <span class="badge badge-success u-fs-11 u-p-2-8 u-ml-auto">%<span id="board-answered-rate">0</span></span>
            </div>
        </div>

        <!-- 3. Lost calls (missed & abandoned) -->
        <div class="card u-p-12-16 u-border-left-4-solid-warning u-d-flex u-col u-jc-space-between">
            <div class="u-flex-between">
                <span class="cc-board-label"><?php echo t('cc_board.missed_call'); ?> / <?php echo t('cc_board.abandoned_call'); ?></span>
                <i class="fas fa-phone-slash u-warning u-opacity-0-7 u-fs-14"></i>
            </div>
            <div class="u-d-flex u-ai-baseline u-gap-6 u-mt-4">
                <span class="cc-board-val-compact u-danger" id="board-missed-calls">0</span>
                <span class="u-fs-13 u-muted u-fw-700">/</span>
                <span class="cc-board-val-compact u-warning" id="board-abandoned-calls">0</span>
                <span class="u-fs-11 u-muted u-ml-auto"><?php echo t('cc_board.abandon'); ?>: %<span id="board-abandon-rate">0</span></span>
                <span id="board-missed-rate" style="display: none;">0</span>
            </div>
        </div>

        <!-- 4. Durations (average wait / talk) -->
        <div class="card u-p-12-16 u-border-left-4-solid-primary u-d-flex u-col u-jc-space-between">
            <div class="u-flex-between">
                <span class="cc-board-label"><?php echo t('cc_board.avg_wait'); ?> / <?php echo t('cc_board.avg_talk'); ?></span>
                <i class="fas fa-stopwatch u-primary u-opacity-0-7 u-fs-14"></i>
            </div>
            <div class="u-d-flex u-ai-baseline u-gap-6 u-mt-4">
                <span class="cc-board-val-compact u-mono u-text-main" id="board-avg-wait">00:00</span>
                <span class="u-fs-13 u-muted">/</span>
                <span class="u-fs-16 u-fw-700 u-mono u-text-main" id="board-avg-talk">00:00</span>
            </div>
        </div>

        <!-- 5. Agents & SLA -->
        <div class="card u-p-12-16 u-border-left-4-solid-6366f1 u-d-flex u-col u-jc-space-between">
            <div class="u-flex-between">
                <span class="cc-board-label"><?php echo t('cc_board.agents_logged_in'); ?> & SLA</span>
                <i class="fas fa-headset u-c-6366f1 u-opacity-0-7 u-fs-14"></i>
            </div>
            <div class="u-d-flex u-ai-baseline u-jc-space-between u-mt-4">
                <div>
                    <span class="cc-board-val-compact u-success" id="board-agents-available">0</span>
                    <span class="u-muted u-fw-600 u-fs-12">/ <span id="board-agents-logged-in">0</span></span>
                    <span class="u-fs-11 u-danger u-fw-600 u-ml-4">(<span id="board-active-calls">0</span> <?php echo t('cc_board.active'); ?>)</span>
                    <span id="board-agents-total" style="display: none;">0</span>
                </div>
                <div class="u-text-right">
                    <span class="u-fs-16 u-fw-700 u-c-6366f1"><span id="board-sla-pct">0</span>%</span>
                    <span class="u-fs-10 u-muted" style="display: block;"><span id="board-sla-threshold">20</span><?php echo t('common.sec_short'); ?></span>
                </div>
            </div>
        </div>
    </div>
    <!-- SECTION 2: live operation tables (waiting calls & agent monitoring) -->
    <!-- Stacked when at least 640px per panel does not fit: based on the real
         content area, not the screen width (992px). The old 1fr/1.1fr columns
         could not get narrower than the table content, so the right panel
         overflowed the screen. -->
    <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-min-100pc-640-1fr u-gap-16">
        <!-- Left panel: live waiting calls (interactive pickup) -->
        <div class="card u-d-flex u-col u-minw-0">
            <div class="card-header u-d-flex u-ai-center u-jc-space-between u-border-bottom-1-solid-border-color u-p-16-16">
                <div class="card-title u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-phone-volume u-danger"></i>
                    <span><?php echo t('cc_supervisor.waiting_calls_live'); ?></span>
                </div>
                <span class="badge badge-danger" id="waiting-badge">0 <?php echo t('cc_board.calls_waiting'); ?></span>
            </div>
            <?php echo uiTableOpen([
                t('cc_supervisor.col_caller'),
                t('cc_supervisor.col_queue'),
                t('cc_supervisor.col_wait_time'),
                ['label' => t('cc_supervisor.col_action'), 'class' => 'text-right'],
            ], ['body_id' => 'sup-waiting-calls-tbody', 'frame' => 'u-flex-1 u-maxh-420 u-overflow-y-auto']); ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted u-p-24">
                                <i class="fas fa-check-circle u-success u-mr-6"></i> <?php echo t('cc_supervisor.no_waiting_calls'); ?>
                            </td>
                        </tr>
                    <?php echo uiTableClose(); ?>
        </div>

        <!-- Right panel: live agent states & pause tracking -->
        <div class="card u-d-flex u-col u-minw-0">
            <div class="card-header u-d-flex u-ai-center u-jc-space-between u-border-bottom-1-solid-border-color u-p-16-16">
                <div class="card-title u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-users-cog u-primary"></i>
                    <span><?php echo t('cc_supervisor.agent_status_title'); ?></span>
                </div>
                <span class="badge badge-info" id="agents-count-badge">0 <?php echo t('cc_board.agents'); ?></span>
            </div>
            <?php echo uiTableOpen([
                t('cc_supervisor.col_extension'),
                t('cc_supervisor.col_agent_name'),
                t('cc_supervisor.col_status'),
                t('cc_supervisor.col_pause_detail'),
                $can_spy ? ['label' => t('cc_supervisor.col_action'), 'class' => 'text-right'] : null,
            ], ['body_id' => 'sup-agents-tbody', 'frame' => 'u-flex-1 u-maxh-420 u-overflow-y-auto']); ?>
                        <tr>
                            <td colspan="<?php echo $can_spy ? 5 : 4; ?>" class="text-center text-muted u-p-24">
                                <?php echo t('cc_supervisor.loading_agents'); ?>
                            </td>
                        </tr>
                    <?php echo uiTableClose(); ?>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/cc_board.css'); ?>">

<script>
// Listen / whisper / barge (api/cc.php spy_call allows only admin/cc_manager).
window.CC_BOARD_CAN_SPY = <?php echo json_encode($can_spy); ?>;
</script>
<script src="<?php echo asset('/assets/js/cc_board.js'); ?>"></script>
