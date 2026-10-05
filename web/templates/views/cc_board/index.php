<div style="display: flex; flex-direction: column; gap: 20px;" id="cc-board-root">

    <!-- Top bar: queue/date filter & full screen -->
    <div class="card page-header-card u-mb-0">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(0, 242, 254, 0.15); display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 20px;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div>
                    <h2 class="u-fs-16 u-fw-700 u-m-0 u-text-main"><?php echo t('sidebar.item_cc_board_unified'); ?></h2>
                    <p style="font-size: 12px; color: var(--text-muted); margin: 2px 0 0 0;"><?php echo t('cc_board.subtitle'); ?></p>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <select id="board-queue-filter" class="form-control form-control-sm" style="width: 190px; font-weight: 600;" onchange="loadAllBoardData()">
                    <option value="ALL">🌐 <?php echo t('cc_board.all_my_queues'); ?> (<?php echo count($my_queues); ?>)</option>
                    <?php foreach ($my_queues as $q): ?>
                        <option value="<?php echo htmlspecialchars($q['queue_name']); ?>">
                            🎧 <?php echo htmlspecialchars($q['title'] ?: $q['queue_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="board-range-filter" class="form-control form-control-sm" style="width: 120px; font-weight: 600;" onchange="loadAllBoardData()">
                    <option value="today"><?php echo t('cc_board.today'); ?></option>
                    <option value="week"><?php echo t('cc_board.this_week'); ?></option>
                    <option value="month"><?php echo t('cc_board.this_month'); ?></option>
                </select>

                <div style="display: flex; align-items: center; gap: 6px; padding: 4px 10px; background: rgba(0, 0, 0, 0.04); border-radius: 6px; font-weight: 700;">
                    <i class="far fa-clock u-primary"></i>
                    <span id="board-clock" style="font-family: monospace; font-size: 13px; color: var(--text-main);">--:--</span>
                    <span id="board-day" style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">-</span>
                </div>

                <button class="btn btn-secondary btn-sm" onclick="toggleBoardFullscreen()" title="<?php echo t('cc_board.fullscreen_tooltip'); ?>">
                    <i class="fas fa-expand" id="board-fullscreen-icon"></i>
                </button>
                <button class="btn btn-primary btn-sm" onclick="loadAllBoardData()" title="<?php echo t('cc_board.refresh_tooltip'); ?>">
                    <i class="fas fa-sync-alt" id="board-refresh-icon"></i> <?php echo t('cc_board.refresh'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- SECTION 1: compact live board and KPI metrics -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px;" class="cc-board-kpi-grid">
        <!-- 1. Waiting calls -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid var(--danger); display: flex; flex-direction: column; justify-content: space-between;">
            <div class="u-flex-between">
                <span class="cc-board-label u-danger"><?php echo t('cc_board.waiting_call'); ?></span>
                <i class="fas fa-phone-volume" style="color: var(--danger); opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 4px;">
                <div class="cc-board-val-compact u-danger" id="board-waiting-calls">0</div>
                <span style="font-size: 11px; color: var(--text-muted); margin-left: auto;">Maks: <span id="board-max-wait" style="font-family: monospace; font-weight: 700; color: var(--text-main);">00:00</span></span>
            </div>
        </div>

        <!-- 2. Answered / total calls -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid var(--secondary); display: flex; flex-direction: column; justify-content: space-between;">
            <div class="u-flex-between">
                <span class="cc-board-label"><?php echo t('cc_board.answered_call'); ?> / <?php echo t('cc_board.total_call'); ?></span>
                <i class="fas fa-phone-alt" style="color: var(--secondary); opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 4px;">
                <span class="cc-board-val-compact" style="color: var(--secondary);" id="board-answered-calls">0</span>
                <span class="u-fs-13 u-muted u-fw-700">/ <span id="board-total-calls">0</span></span>
                <span class="badge badge-success" style="font-size: 11px; padding: 2px 6px; margin-left: auto;">%<span id="board-answered-rate">0</span></span>
            </div>
        </div>

        <!-- 3. Lost calls (missed & abandoned) -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid var(--warning); display: flex; flex-direction: column; justify-content: space-between;">
            <div class="u-flex-between">
                <span class="cc-board-label"><?php echo t('cc_board.missed_call'); ?> / <?php echo t('cc_board.abandoned_call'); ?></span>
                <i class="fas fa-phone-slash" style="color: var(--warning); opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 4px;">
                <span class="cc-board-val-compact u-danger" id="board-missed-calls">0</span>
                <span class="u-fs-13 u-muted u-fw-700">/</span>
                <span class="cc-board-val-compact u-warning" id="board-abandoned-calls">0</span>
                <span style="font-size: 11px; color: var(--text-muted); margin-left: auto;">Terk: %<span id="board-abandon-rate">0</span></span>
                <span id="board-missed-rate" style="display: none;">0</span>
            </div>
        </div>

        <!-- 4. Durations (average wait / talk) -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid var(--primary); display: flex; flex-direction: column; justify-content: space-between;">
            <div class="u-flex-between">
                <span class="cc-board-label"><?php echo t('cc_board.avg_wait'); ?> / <?php echo t('cc_board.avg_talk'); ?></span>
                <i class="fas fa-stopwatch" style="color: var(--primary); opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; gap: 6px; margin-top: 4px;">
                <span class="cc-board-val-compact" style="font-family: monospace; color: var(--text-main);" id="board-avg-wait">00:00</span>
                <span class="u-fs-13 u-muted">/</span>
                <span style="font-size: 15px; font-weight: 700; font-family: monospace; color: var(--text-main);" id="board-avg-talk">00:00</span>
            </div>
        </div>

        <!-- 5. Temsilciler & SLA -->
        <div class="card cc-board-tile" style="padding: 12px 14px; border-left: 4px solid #6366f1; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="u-flex-between">
                <span class="cc-board-label"><?php echo t('cc_board.agents_logged_in'); ?> & SLA</span>
                <i class="fas fa-headset" style="color: #6366f1; opacity: 0.7; font-size: 14px;"></i>
            </div>
            <div style="display: flex; align-items: baseline; justify-content: space-between; margin-top: 4px;">
                <div>
                    <span class="cc-board-val-compact u-success" id="board-agents-available">0</span>
                    <span class="u-muted u-fw-600 u-fs-12">/ <span id="board-agents-logged-in">0</span></span>
                    <span style="font-size: 11px; color: var(--danger); font-weight: 600; margin-left: 4px;">(<span id="board-active-calls">0</span> aktif)</span>
                    <span id="board-agents-total" style="display: none;">0</span>
                </div>
                <div class="u-text-right">
                    <span style="font-size: 15px; font-weight: 800; color: #6366f1;"><span id="board-sla-pct">0</span>%</span>
                    <span style="font-size: 10px; color: var(--text-muted); display: block;"><span id="board-sla-threshold">20</span>sn</span>
                </div>
            </div>
        </div>
    </div>
    <!-- SECTION 2: live operation tables (waiting calls & agent monitoring) -->
    <!-- Stacked when at least 640px per panel does not fit: based on the real
         content area, not the screen width (992px). The old 1fr/1.1fr columns
         could not get narrower than the table content, so the right panel
         overflowed the screen. -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 640px), 1fr)); gap: 16px;" class="cc-board-ops-grid">
        <!-- Left panel: live waiting calls (interactive pickup) -->
        <div class="card" style="display: flex; flex-direction: column; min-width: 0;">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding: 14px 16px;">
                <div class="card-title" style="display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 14px;">
                    <i class="fas fa-phone-volume u-danger"></i>
                    <span><?php echo t('cc_supervisor.waiting_calls_live'); ?></span>
                </div>
                <span class="badge badge-danger" id="waiting-badge">0 Çağrı Bekliyor</span>
            </div>
            <div class="table-responsive" style="flex: 1; max-height: 420px; overflow-y: auto;">
                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th><?php echo t('cc_supervisor.col_caller'); ?></th>
                            <th><?php echo t('cc_supervisor.col_queue'); ?></th>
                            <th><?php echo t('cc_supervisor.col_wait_time'); ?></th>
                            <th class="text-right"><?php echo t('cc_supervisor.col_action'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sup-waiting-calls-tbody">
                        <tr>
                            <td colspan="4" class="text-center text-muted u-p-24">
                                <i class="fas fa-check-circle u-success u-mr-6"></i> <?php echo t('cc_supervisor.no_waiting_calls'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right panel: live agent states & pause tracking -->
        <div class="card" style="display: flex; flex-direction: column; min-width: 0;">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding: 14px 16px;">
                <div class="card-title" style="display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 14px;">
                    <i class="fas fa-users-cog u-primary"></i>
                    <span><?php echo t('cc_supervisor.agent_status_title'); ?></span>
                </div>
                <span class="badge badge-info" id="agents-count-badge">0 Temsilci</span>
            </div>
            <div class="table-responsive" style="flex: 1; max-height: 420px; overflow-y: auto;">
                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th><?php echo t('cc_supervisor.col_extension'); ?></th>
                            <th><?php echo t('cc_supervisor.col_agent_name'); ?></th>
                            <th><?php echo t('cc_supervisor.col_status'); ?></th>
                            <th><?php echo t('cc_supervisor.col_pause_detail'); ?></th>
                            <?php if ($can_spy): ?><th class="text-right"><?php echo t('cc_supervisor.col_action'); ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="sup-agents-tbody">
                        <tr>
                            <td colspan="<?php echo $can_spy ? 5 : 4; ?>" class="text-center text-muted u-p-24">
                                <?php echo t('cc_supervisor.loading_agents'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/cc_board.css'); ?>">

<script>
// Listen / whisper / barge (api/cc.php spy_call allows only admin/cc_manager).
window.CC_BOARD_CAN_SPY = <?php echo json_encode($can_spy); ?>;
</script>
<script src="<?php echo asset('/assets/js/cc_board.js'); ?>"></script>
