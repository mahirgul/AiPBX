<?php
/**
 * System -> Scheduled Tasks (/scheduled-tasks)
 * Roadmap 2.1: Administrators run sandboxed shell, PHP, and Python scripts on a schedule.
 *
 * @var list<array<string, mixed>> $tasks
 * @var bool $isEnabled
 * @var array<string, string> $presets
 */
$csrf = getCSRFToken();
?>
<div class="card u-mb-20">
    <?php echo uiPageHeaderOpen('fa-calendar-check', htmlspecialchars(t('scheduled_tasks.page_title', 'Scheduled Tasks')), 'scheduledTasksHelpBox'); ?>
<span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($tasks); ?></span>
<?php echo uiPageHeaderActions(); ?>
<div class="u-flex-center u-gap-8">
            <button type="button" class="btn btn-primary btn-sm" onclick="showNewTaskForm()">
                <i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars(t('scheduled_tasks.btn_new', 'New Task')); ?>
            </button>
        </div>
<?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('scheduledTasksHelpBox', htmlspecialchars(t('scheduled_tasks.help_title', 'Sandboxed Scheduled Scripts'))); ?>
        <p class="u-m-4-0-0"><?php echo htmlspecialchars(t('scheduled_tasks.help_body', 'Run shell, PHP, or Python scripts on a cron schedule inside an isolated systemd sandbox. Execution runs as an unprivileged user with read-only system access, strict memory and CPU limits, and no network access by default.')); ?></p>
<?php echo uiHelpBoxClose(); ?>

    <!-- Master Switch Card -->
    <div style="margin: 0 20px 20px; padding: 14px 18px; background: var(--bg-subtle, rgba(2,132,199,0.04)); border: 1px solid var(--border-color); border-radius: var(--radius-sm, 6px); display: flex; align-items: center; justify-content: space-between;">
        <div>
            <div class="u-fw-700 u-fs-14">
                <?php echo htmlspecialchars(t('scheduled_tasks.feature_status', 'Feature Status')); ?>:
                <?php if ($isEnabled): ?>
                    <span class="badge badge-success"><?php echo htmlspecialchars(t('scheduled_tasks.feature_enabled', 'Enabled (Active)')); ?></span>
                <?php else: ?>
                    <span class="badge badge-secondary"><?php echo htmlspecialchars(t('scheduled_tasks.feature_disabled', 'Disabled (Inactive)')); ?></span>
                <?php endif; ?>
            </div>
            <div class="u-muted u-fs-12 u-mt-2">
                <?php echo htmlspecialchars(t('scheduled_tasks.status_desc', 'When disabled, scheduled runs are suppressed; tasks can still be executed manually with "Run Now".')); ?>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="toggle_enable">
            <input type="hidden" name="enabled" value="<?php echo $isEnabled ? '0' : '1'; ?>">
            <?php if ($isEnabled): ?>
                <button type="submit" class="btn btn-secondary btn-sm">
                    <i class="fas fa-power-off"></i> <?php echo htmlspecialchars(t('scheduled_tasks.btn_disable', 'Disable All')); ?>
                </button>
            <?php else: ?>
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="fas fa-play"></i> <?php echo htmlspecialchars(t('scheduled_tasks.btn_enable', 'Enable Scheduled Execution')); ?>
                </button>
            <?php endif; ?>
        </form>
    </div>

    <!-- Task Form Box (Create / Edit) -->
    <div id="taskFormBox" style="display: none; padding: 20px; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); background: var(--card-bg, #fff);">
        <h4 id="taskFormTitle" class="u-mt-0 u-mb-16">
            <i class="fas fa-plus"></i> <?php echo htmlspecialchars(t('scheduled_tasks.btn_new', 'New Task')); ?>
        </h4>
        <form method="POST" id="taskForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="save_task">
            <input type="hidden" name="id" id="task_id" value="">

            <div class="u-grid-2 u-gap-16 u-mb-16">
                <div>
                    <label class="form-label" for="task_name"><strong><?php echo htmlspecialchars(t('scheduled_tasks.task_name', 'Task Name')); ?> *</strong></label>
                    <input type="text" name="name" id="task_name" class="form-control" required maxlength="100" placeholder="<?php echo htmlspecialchars(t('scheduled_tasks.ph_name')); ?>">
                </div>
                <div>
                    <label class="form-label" for="task_script_type"><strong><?php echo htmlspecialchars(t('scheduled_tasks.script_type', 'Interpreter')); ?> *</strong></label>
                    <select name="script_type" id="task_script_type" class="form-control">
                        <option value="shell">Shell (bash)</option>
                        <option value="php">PHP (cli)</option>
                        <option value="python">Python 3</option>
                    </select>
                </div>
            </div>

            <div class="u-mb-16">
                <label class="form-label" for="task_description"><?php echo htmlspecialchars(t('scheduled_tasks.description', 'Description')); ?></label>
                <input type="text" name="description" id="task_description" class="form-control" placeholder="<?php echo htmlspecialchars(t('scheduled_tasks.ph_description')); ?>">
            </div>

            <div class="u-grid-2 u-gap-16 u-mb-16">
                <div>
                    <label class="form-label" for="task_cron"><strong><?php echo htmlspecialchars(t('scheduled_tasks.cron_expression', 'Cron Schedule')); ?> *</strong></label>
                    <div class="u-d-flex u-gap-8">
                        <input type="text" name="cron_expression" id="task_cron" class="form-control u-mono" required value="0 3 * * *">
                        <select class="form-control u-maxw-160" onchange="if(this.value){ document.getElementById('task_cron').value = this.value; }">
                            <option value=""><?php echo htmlspecialchars(t('scheduled_tasks.preset')); ?></option>
                            <option value="*/5 * * * *"><?php echo htmlspecialchars(t('scheduled_tasks.preset_5min')); ?></option>
                            <option value="*/15 * * * *"><?php echo htmlspecialchars(t('scheduled_tasks.preset_15min')); ?></option>
                            <option value="0 * * * *"><?php echo htmlspecialchars(t('scheduled_tasks.preset_hourly')); ?></option>
                            <option value="0 3 * * *"><?php echo htmlspecialchars(t('scheduled_tasks.preset_nightly')); ?></option>
                            <option value="0 3 * * 0"><?php echo htmlspecialchars(t('scheduled_tasks.preset_weekly')); ?></option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="form-label"><strong><?php echo htmlspecialchars(t('scheduled_tasks.resource_limits', 'Resource Limits')); ?></strong></label>
                    <div class="u-d-flex u-gap-8">
                        <input type="number" name="timeout_sec" id="task_timeout" class="form-control" value="300" min="5" max="3600" title="<?php echo htmlspecialchars(t('scheduled_tasks.ph_timeout')); ?>" placeholder="<?php echo htmlspecialchars(t('scheduled_tasks.ph_timeout')); ?>">
                        <input type="number" name="memory_limit_mb" id="task_memory" class="form-control" value="256" min="32" max="4096" title="<?php echo htmlspecialchars(t('scheduled_tasks.ph_memory')); ?>" placeholder="<?php echo htmlspecialchars(t('scheduled_tasks.ph_memory')); ?>">
                        <input type="number" name="cpu_limit_pct" id="task_cpu" class="form-control" value="50" min="5" max="100" title="<?php echo htmlspecialchars(t('scheduled_tasks.ph_cpu')); ?>" placeholder="<?php echo htmlspecialchars(t('scheduled_tasks.ph_cpu')); ?>">
                    </div>
                </div>
            </div>

            <div class="u-mb-16">
                <label class="u-check-label u-fs-13">
                    <input type="checkbox" name="network_access" id="task_network" value="1" class="u-accent">
                    <strong><?php echo htmlspecialchars(t('scheduled_tasks.network_access', 'Network Access')); ?>:</strong>
                    <?php echo htmlspecialchars(t('scheduled_tasks.network_access_desc', 'Allow outgoing network connections from the sandbox')); ?>
                </label>
            </div>

            <div class="u-mb-16">
                <label class="form-label" for="task_script_content"><strong><?php echo htmlspecialchars(t('scheduled_tasks.script_content', 'Script Code')); ?> *</strong></label>
                <textarea name="script_content" id="task_script_content" class="form-control u-mono u-fs-13 u-tab-size-4" rows="10" required placeholder="#!/bin/bash&#10;echo &quot;Running task...&quot;"></textarea>
            </div>

            <div class="u-mb-16">
                <label class="u-check-label u-fs-13">
                    <input type="checkbox" name="is_active" id="task_active" value="1" checked class="u-accent">
                    <?php echo htmlspecialchars(t('common.active', 'Active (Schedule enabled)')); ?>
                </label>
            </div>

            <div class="u-flex-gap u-jc-flex-end">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('taskFormBox').style.display='none';">
                    <?php echo htmlspecialchars(t('common.cancel', 'Cancel')); ?>
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('common.save', 'Save Task')); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Task List Table -->
    <?php echo uiTableOpen([
        htmlspecialchars(t('scheduled_tasks.task_name', 'Task')),
        htmlspecialchars(t('scheduled_tasks.cron_expression', 'Schedule')),
        htmlspecialchars(t('scheduled_tasks.script_type', 'Type')),
        htmlspecialchars(t('scheduled_tasks.last_run', 'Last Run')),
        htmlspecialchars(t('common.status', 'Status')),
        ['label' => htmlspecialchars(t('common.actions', 'Actions')), 'class' => 'text-right'],
    ]); ?>
                <?php if (empty($tasks)): ?>
                    <tr>
                        <td colspan="6" class="text-center u-muted u-p-30">
                            <i class="fas fa-calendar-xmark fa-2x u-mb-8 u-opacity-0-5" style="display: block;"></i>
                            <?php echo htmlspecialchars(t('scheduled_tasks.empty', 'No scheduled tasks configured yet.')); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tasks as $t): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$t['name']); ?></strong>
                                <?php if (!empty($t['description'])): ?>
                                    <div class="u-muted u-fs-12"><?php echo htmlspecialchars((string)$t['description']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars((string)$t['cron_expression']); ?></code>
                                <?php if (!empty($t['next_runs'])): ?>
                                    <div class="u-muted u-fs-11" title="<?php echo htmlspecialchars(t('scheduled_tasks.next_run')); ?>">
                                        <i class="fas fa-clock"></i> <?php echo htmlspecialchars($t['next_runs'][0]); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-info u-fs-11"><?php echo htmlspecialchars((string)$t['script_type']); ?></span>
                                <?php if (!empty($t['network_access'])): ?>
                                    <span class="badge badge-warning u-fs-11" title="Network Access Enabled"><i class="fas fa-globe"></i></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($t['last_run_at'])): ?>
                                    <div class="u-fs-12"><?php echo htmlspecialchars((string)$t['last_run_at']); ?></div>
                                    <?php if ($t['last_status'] === 'success'): ?>
                                        <span class="badge badge-success u-fs-10">OK</span>
                                    <?php elseif ($t['last_status'] === 'failed'): ?>
                                        <span class="badge badge-danger u-fs-10">FAIL</span>
                                    <?php elseif ($t['last_status'] === 'timeout'): ?>
                                        <span class="badge badge-warning u-fs-10">TIMEOUT</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="u-muted u-fs-12">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($t['is_active'])): ?>
                                    <span class="badge badge-success"><?php echo htmlspecialchars(t('common.active', 'Active')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars(t('common.passive', 'Disabled')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-icon btn-primary btn-sm" onclick="runTaskNow(<?php echo (int)$t['id']; ?>)" title="<?php echo htmlspecialchars(t('scheduled_tasks.btn_run_now', 'Run Now')); ?>">
                                    <i class="fas fa-play"></i>
                                </button>
                                <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="showHistory(<?php echo (int)$t['id']; ?>)" title="<?php echo htmlspecialchars(t('scheduled_tasks.btn_history', 'History & Logs')); ?>">
                                    <i class="fas fa-history"></i>
                                </button>
                                <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick='editTask(<?php echo json_encode($t, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' title="<?php echo htmlspecialchars(t('common.edit', 'Edit')); ?>">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <form method="POST" class="u-d-inline" onsubmit="return confirm(<?php echo jsArg(t('scheduled_tasks.confirm_delete', 'Are you sure you want to delete this scheduled task?')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="action" value="delete_task">
                                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                    <button type="submit" class="btn btn-icon btn-danger btn-sm" title="<?php echo htmlspecialchars(t('common.delete', 'Delete')); ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>

<!-- Modal: Run Output -->
<div class="u-pos-fixed u-inset-0 u-bg-rgba-0-0-0-0-6 u-z-index-9999 u-ai-center u-jc-center u-p-20" id="outputModal" style="display: none;">
    <div style="background: var(--card-bg, #fff); border-radius: var(--radius-md, 8px); max-width: 800px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);">
        <div class="u-p-16-20 u-border-bottom-1-solid-border-color u-d-flex u-jc-space-between u-ai-center">
            <div class="u-fw-700 u-fs-16" id="outputModalTitle">
                <i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('scheduled_tasks.run_output', 'Task Execution Output')); ?>
            </div>
            <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="document.getElementById('outputModal').style.display='none';">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="u-p-20 u-overflow-y-auto u-flex-1">
            <div id="outputModalStatus" class="u-mb-12 u-fw-600"></div>
            <pre class="u-bg-1e1e1e u-c-d4d4d4 u-p-16 u-r-8 u-mono u-fs-12 u-maxh-400 u-of-auto u-white-space-pre-wrap u-word-break-break-all u-m-0" id="outputModalText"></pre>
        </div>
        <div class="u-p-12-20 u-border-top-1-solid-border-color u-ta-right">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('outputModal').style.display='none';">
                <?php echo htmlspecialchars(t('common.close', 'Close')); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: History & Versions -->
<div class="u-pos-fixed u-inset-0 u-bg-rgba-0-0-0-0-6 u-z-index-9999 u-ai-center u-jc-center u-p-20" id="historyModal" style="display: none;">
    <div style="background: var(--card-bg, #fff); border-radius: var(--radius-md, 8px); max-width: 900px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);">
        <div class="u-p-16-20 u-border-bottom-1-solid-border-color u-d-flex u-jc-space-between u-ai-center">
            <div class="u-fw-700 u-fs-16" id="historyModalTitle">
                <i class="fas fa-history"></i> <?php echo htmlspecialchars(t('scheduled_tasks.btn_history', 'Run History & Versions')); ?>
            </div>
            <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="document.getElementById('historyModal').style.display='none';">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="u-p-20 u-overflow-y-auto u-flex-1">
            <h5><?php echo htmlspecialchars(t('scheduled_tasks.recent_runs', 'Recent Runs')); ?></h5>
            <?php echo uiTableOpen([
                'Started',
                'Trigger',
                'Duration',
                'Exit Code',
                'Status',
            ], ['body_id' => 'historyRunsBody', 'frame' => 'u-mb-24']); ?><?php echo uiTableClose(); ?>

            <h5><?php echo htmlspecialchars(t('scheduled_tasks.version_history', 'Script Versions')); ?></h5>
            <?php echo uiTableOpen([
                'Version',
                'Date',
                'Preview',
            ], ['body_id' => 'historyVersionsBody']); ?><?php echo uiTableClose(); ?>
        </div>
        <div class="u-p-12-20 u-border-top-1-solid-border-color u-ta-right">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('historyModal').style.display='none';">
                <?php echo htmlspecialchars(t('common.close', 'Close')); ?>
            </button>
        </div>
    </div>
</div>

<script>
function showNewTaskForm() {
    document.getElementById('taskFormTitle').innerHTML = '<i class="fas fa-plus"></i> ' + <?php echo jsValue(t('scheduled_tasks.btn_new', 'New Task')); ?>;
    document.getElementById('taskForm').reset();
    document.getElementById('task_id').value = '';
    document.getElementById('task_cron').value = '0 3 * * *';
    document.getElementById('task_timeout').value = '300';
    document.getElementById('task_memory').value = '256';
    document.getElementById('task_cpu').value = '50';
    document.getElementById('task_active').checked = true;
    document.getElementById('taskFormBox').style.display = 'block';
    document.getElementById('taskFormBox').scrollIntoView({ behavior: 'smooth' });
}

function editTask(t) {
    document.getElementById('taskFormTitle').innerHTML = '<i class="fas fa-pencil-alt"></i> ' + <?php echo jsValue(t('common.edit', 'Edit Task')); ?> + ': ' + escapeHtml(t.name || '');
    document.getElementById('task_id').value = t.id;
    document.getElementById('task_name').value = t.name || '';
    document.getElementById('task_description').value = t.description || '';
    document.getElementById('task_script_type').value = t.script_type || 'shell';
    document.getElementById('task_cron').value = t.cron_expression || '0 3 * * *';
    document.getElementById('task_timeout').value = t.timeout_sec || 300;
    document.getElementById('task_memory').value = t.memory_limit_mb || 256;
    document.getElementById('task_cpu').value = t.cpu_limit_pct || 50;
    document.getElementById('task_network').checked = parseInt(t.network_access) === 1;
    document.getElementById('task_script_content').value = t.script_content || '';
    document.getElementById('task_active').checked = parseInt(t.is_active) === 1;

    document.getElementById('taskFormBox').style.display = 'block';
    document.getElementById('taskFormBox').scrollIntoView({ behavior: 'smooth' });
}

function taskPost(action, taskId) {
    var data = new URLSearchParams();
    data.append('csrf_token', <?php echo jsValue($csrf); ?>);
    data.append('action', action);
    data.append('id', taskId);
    return fetch('/scheduled-tasks', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: data.toString()
    }).then(function(r) { return r.json(); });
}

// "Run now" is queued: the dispatcher starts the task in its sandbox within a
// minute; the newest run is then followed until it ends (at most 10 minutes).
function runTaskNow(taskId) {
    var modal = document.getElementById('outputModal');
    var status = document.getElementById('outputModalStatus');
    var output = document.getElementById('outputModalText');
    var badge = function(cls, icon, text) {
        status.innerHTML = '';
        var b = document.createElement('span');
        b.className = 'badge ' + cls;
        b.innerHTML = '<i class="fas ' + icon + '"></i> ';
        b.appendChild(document.createTextNode(text));
        status.appendChild(b);
    };
    output.textContent = '';
    modal.style.display = 'flex';
    badge('badge-info', 'fa-spinner fa-spin', __('js.scheduled_tasks.queued'));

    var lastRun = 0;
    taskPost('get_details', taskId).then(function(res) {
        var runs = (res.task && res.task.runs) || [];
        lastRun = runs.length ? parseInt(runs[0].id, 10) : 0;
        return taskPost('run_now', taskId);
    }).then(function(res) {
        if (!res.ok) {
            badge('badge-danger', 'fa-times', res.error || __('js.scheduled_tasks.failed'));
            return;
        }
        var tries = 0;
        var poll = function() {
            if (++tries > 200) {
                badge('badge-warning', 'fa-clock', __('js.scheduled_tasks.still_waiting'));
                return;
            }
            taskPost('get_details', taskId).then(function(d) {
                var runs = (d.task && d.task.runs) || [];
                var run = runs.length && parseInt(runs[0].id, 10) > lastRun ? runs[0] : null;
                if (!run) {
                    setTimeout(poll, 3000);
                    return;
                }
                if (run.status === 'running') {
                    badge('badge-info', 'fa-spinner fa-spin', __('js.scheduled_tasks.running'));
                    setTimeout(poll, 3000);
                    return;
                }
                var ok = parseInt(run.exit_code, 10) === 0;
                badge(ok ? 'badge-success' : 'badge-danger', ok ? 'fa-check' : 'fa-times',
                    __(ok ? 'js.scheduled_tasks.finished' : 'js.scheduled_tasks.failed_code', String(run.exit_code)));
                output.textContent = run.output || run.error_message || __('js.scheduled_tasks.no_output');
            }).catch(function() { setTimeout(poll, 5000); });
        };
        setTimeout(poll, 3000);
    }).catch(function(err) {
        badge('badge-danger', 'fa-times', err.message);
    });
}

function showHistory(taskId) {
    var modal = document.getElementById('historyModal');
    var runsBody = document.getElementById('historyRunsBody');
    var versBody = document.getElementById('historyVersionsBody');

    runsBody.innerHTML = '<tr><td colspan="5" class="text-center u-muted"><i class="fas fa-spinner fa-spin"></i> ' + __('js.common.loading') + '</td></tr>';
    versBody.innerHTML = '<tr><td colspan="3" class="text-center u-muted"><i class="fas fa-spinner fa-spin"></i> ' + __('js.common.loading') + '</td></tr>';
    modal.style.display = 'flex';

    var data = new URLSearchParams();
    data.append('csrf_token', <?php echo jsValue($csrf); ?>);
    data.append('action', 'get_details');
    data.append('id', taskId);

    fetch('/scheduled-tasks', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.ok || !res.task) {
            runsBody.innerHTML = '<tr><td colspan="5" class="text-center u-muted">' + __('js.scheduled_tasks.load_failed') + '</td></tr>';
            return;
        }

        var t = res.task;
        document.getElementById('historyModalTitle').innerHTML = '<i class="fas fa-history"></i> ' + <?php echo jsValue(t('scheduled_tasks.btn_history')); ?> + ': ' + escapeHtml(t.name || '');

        if (!t.runs || t.runs.length === 0) {
            runsBody.innerHTML = '<tr><td colspan="5" class="text-center u-muted">' + __('js.scheduled_tasks.no_runs') + '</td></tr>';
        } else {
            runsBody.innerHTML = t.runs.map(function(r) {
                var badge = r.status === 'success' ? 'badge-success' : (r.status === 'running' ? 'badge-info' : 'badge-danger');
                var dur = r.duration_ms ? (r.duration_ms / 1000).toFixed(2) + 's' : '—';
                return '<tr>' +
                    '<td>' + escapeHtml(r.started_at || '') + '</td>' +
                    '<td><span class="badge badge-secondary">' + escapeHtml(r.triggered_by || 'schedule') + '</span></td>' +
                    '<td>' + dur + '</td>' +
                    '<td><code>' + (r.exit_code !== null ? escapeHtml(String(r.exit_code)) : '—') + '</code></td>' +
                    '<td><span class="badge ' + badge + '">' + escapeHtml(r.status || '') + '</span></td>' +
                '</tr>';
            }).join('');
        }

        if (!t.versions || t.versions.length === 0) {
            versBody.innerHTML = '<tr><td colspan="3" class="text-center u-muted">' + __('js.scheduled_tasks.no_versions') + '</td></tr>';
        } else {
            versBody.innerHTML = t.versions.map(function(v) {
                var snippet = (v.script_content || '').substring(0, 60).replace(/[\n\r]+/g, ' ');
                return '<tr>' +
                    '<td><strong>v' + escapeHtml(String(v.version_num)) + '</strong></td>' +
                    '<td>' + escapeHtml(v.created_at || '') + '</td>' +
                    '<td><code>' + escapeHtml(snippet) + '</code></td>' +
                '</tr>';
            }).join('');
        }
    })
    .catch(function(err) {
        runsBody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">' + __('js.common.error_colon') + escapeHtml(err.message) + '</td></tr>';
    });
}
</script>
