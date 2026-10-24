<?php
/**
 * Integrations -> Database Connections (/external-dbs)
 * Roadmap 2.2: Stored encrypted connections to MySQL/MariaDB and PostgreSQL.
 *
 * @var list<array<string, mixed>> $connections
 * @var array<string, string> $drivers
 */
$csrf = getCSRFToken();
?>
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-database u-primary"></i> <?php echo htmlspecialchars(t('external_dbs.page_title', 'External Databases')); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($connections); ?></span>
        </div>
        <div class="u-flex-center" style="gap: 8px;">
            <button type="button" class="btn btn-primary btn-sm" onclick="showNewDbForm()">
                <i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars(t('external_dbs.btn_new', 'New Connection')); ?>
            </button>
        </div>
    </div>

    <div class="module-help-box" style="display: block; margin: 16px 20px 20px;">
        <h4><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('external_dbs.help_title', 'External Database Connections')); ?></h4>
        <p style="margin: 4px 0 0;"><?php echo htmlspecialchars(t('external_dbs.help_body', 'Securely store encrypted credentials for external MySQL/MariaDB and PostgreSQL databases. Used by IVR Data Steps for caller lookups and routing, as well as by custom plugins.')); ?></p>
        <div style="margin-top: 8px; font-weight: 600; color: var(--warning, #d97706);">
            <i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars(t('external_dbs.read_only_advice', 'Security Recommendation: Always configure dedicated read-only user accounts on your external database servers.')); ?>
        </div>
    </div>

    <!-- Create / Edit Form Box -->
    <div id="dbFormBox" style="display: none; padding: 20px; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); background: var(--card-bg, #fff);">
        <h4 id="dbFormTitle" style="margin-top: 0; margin-bottom: 16px;">
            <i class="fas fa-plus"></i> <?php echo htmlspecialchars(t('external_dbs.btn_new', 'New Connection')); ?>
        </h4>
        <form method="POST" id="dbForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="save_connection">
            <input type="hidden" name="id" id="db_id" value="">

            <div class="u-grid-2" style="gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" for="db_name"><strong><?php echo htmlspecialchars(t('external_dbs.name', 'Connection Name')); ?> *</strong></label>
                    <input type="text" name="name" id="db_name" class="form-control" required maxlength="100" placeholder="<?php echo htmlspecialchars(t('external_dbs.ph_name')); ?>">
                </div>
                <div>
                    <label class="form-label" for="db_driver"><strong><?php echo htmlspecialchars(t('external_dbs.driver', 'Driver')); ?> *</strong></label>
                    <select name="driver" id="db_driver" class="form-control" onchange="updateDefaultPort(this.value)">
                        <?php foreach ($drivers as $k => $label): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="u-grid-2" style="gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" for="db_host"><strong><?php echo htmlspecialchars(t('external_dbs.host', 'Host / IP Address')); ?> *</strong></label>
                    <input type="text" name="host" id="db_host" class="form-control" required value="" placeholder="db.example.com">
                </div>
                <div>
                    <label class="form-label" for="db_port"><strong><?php echo htmlspecialchars(t('external_dbs.port', 'Port')); ?> *</strong></label>
                    <input type="number" name="port" id="db_port" class="form-control" required value="3306" min="1" max="65535">
                </div>
            </div>

            <div class="u-grid-2" style="gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" for="db_database_name"><strong><?php echo htmlspecialchars(t('external_dbs.database_name', 'Database Name')); ?> *</strong></label>
                    <input type="text" name="database_name" id="db_database_name" class="form-control" required placeholder="crm_db">
                </div>
                <div>
                    <label class="form-label" for="db_username"><strong><?php echo htmlspecialchars(t('external_dbs.username', 'Username')); ?> *</strong></label>
                    <input type="text" name="username" id="db_username" class="form-control" required placeholder="crm_readonly">
                </div>
            </div>

            <div class="u-grid-2" style="gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" for="db_password"><strong><?php echo htmlspecialchars(t('external_dbs.password', 'Password')); ?></strong></label>
                    <input type="password" name="password" id="db_password" class="form-control" autocomplete="new-password" placeholder="<?php echo htmlspecialchars(t('external_dbs.password_keep')); ?>">
                </div>
                <div>
                    <label class="form-label" for="db_timeout"><strong><?php echo htmlspecialchars(t('external_dbs.timeout', 'Timeout (seconds)')); ?></strong></label>
                    <input type="number" name="timeout_sec" id="db_timeout" class="form-control" value="3" min="1" max="10">
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="u-check-label u-fs-13">
                    <input type="checkbox" name="is_active" id="db_active" value="1" checked class="u-accent">
                    <?php echo htmlspecialchars(t('common.active', 'Active')); ?>
                </label>
            </div>

            <div class="u-flex-gap" style="justify-content: space-between; align-items: center;">
                <div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="testFormConnection()">
                        <i class="fas fa-plug"></i> <?php echo htmlspecialchars(t('external_dbs.btn_test', 'Test Connection')); ?>
                    </button>
                    <span id="testFeedback" class="u-fs-12 u-ml-8"></span>
                </div>
                <div class="u-flex-gap">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('dbFormBox').style.display='none';">
                        <?php echo htmlspecialchars(t('common.cancel', 'Cancel')); ?>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('common.save', 'Save Connection')); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Connection List Table -->
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('external_dbs.name', 'Connection')); ?></th>
                    <th><?php echo htmlspecialchars(t('external_dbs.driver', 'Driver')); ?></th>
                    <th><?php echo htmlspecialchars(t('external_dbs.target', 'Target')); ?></th>
                    <th><?php echo htmlspecialchars(t('external_dbs.status', 'Test Status')); ?></th>
                    <th><?php echo htmlspecialchars(t('common.status', 'Status')); ?></th>
                    <th class="text-right"><?php echo htmlspecialchars(t('common.actions', 'Actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($connections)): ?>
                    <tr>
                        <td colspan="6" class="text-center u-muted" style="padding: 30px;">
                            <i class="fas fa-database fa-2x u-mb-8" style="display: block; opacity: 0.5;"></i>
                            <?php echo htmlspecialchars(t('external_dbs.empty', 'No external database connections configured yet.')); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($connections as $c): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$c['name']); ?></strong>
                                <div class="u-muted u-fs-11">User: <code><?php echo htmlspecialchars((string)$c['username']); ?></code></div>
                            </td>
                            <td>
                                <span class="badge badge-info u-fs-11"><?php echo htmlspecialchars($drivers[$c['driver']] ?? (string)$c['driver']); ?></span>
                            </td>
                            <td>
                                <div><code><?php echo htmlspecialchars((string)$c['host'] . ':' . (int)$c['port']); ?></code></div>
                                <div class="u-muted u-fs-11">DB: <?php echo htmlspecialchars((string)$c['database_name']); ?></div>
                            </td>
                            <td>
                                <?php if (!empty($c['last_tested_at'])): ?>
                                    <?php if ($c['last_test_status'] === 'ok'): ?>
                                        <span class="badge badge-success u-fs-11"><i class="fas fa-check"></i> OK</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger u-fs-11" title="<?php echo htmlspecialchars((string)($c['last_test_message'] ?? '')); ?>"><i class="fas fa-times"></i> Error</span>
                                    <?php endif; ?>
                                    <div class="u-muted u-fs-11"><?php echo htmlspecialchars((string)$c['last_tested_at']); ?></div>
                                <?php else: ?>
                                    <span class="u-muted u-fs-12">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['is_active'])): ?>
                                    <span class="badge badge-success"><?php echo htmlspecialchars(t('common.active', 'Active')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars(t('common.passive', 'Disabled')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="testSavedConnection(<?php echo (int)$c['id']; ?>)" title="<?php echo htmlspecialchars(t('external_dbs.btn_test', 'Test Connection')); ?>">
                                    <i class="fas fa-plug"></i>
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick='editDb(<?php echo json_encode($c, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' title="<?php echo htmlspecialchars(t('common.edit', 'Edit')); ?>">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm(<?php echo jsArg(t('external_dbs.confirm_delete', 'Are you sure you want to delete this database connection?')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="action" value="delete_connection">
                                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="<?php echo htmlspecialchars(t('common.delete', 'Delete')); ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function updateDefaultPort(driver) {
    var portInput = document.getElementById('db_port');
    if (driver === 'pgsql') {
        portInput.value = '5432';
    } else if (driver === 'mysql') {
        portInput.value = '3306';
    }
}

function showNewDbForm() {
    document.getElementById('dbFormTitle').innerHTML = '<i class="fas fa-plus"></i> ' + <?php echo jsArg(t('external_dbs.btn_new', 'New Connection')); ?>;
    document.getElementById('dbForm').reset();
    document.getElementById('db_id').value = '';
    document.getElementById('db_port').value = '3306';
    document.getElementById('db_timeout').value = '3';
    document.getElementById('db_active').checked = true;
    document.getElementById('testFeedback').textContent = '';
    document.getElementById('dbFormBox').style.display = 'block';
    document.getElementById('dbFormBox').scrollIntoView({ behavior: 'smooth' });
}

function editDb(c) {
    document.getElementById('dbFormTitle').innerHTML = '<i class="fas fa-pencil-alt"></i> ' + <?php echo jsArg(t('common.edit', 'Edit Connection')); ?> + ': ' + escapeHtml(c.name || '');
    document.getElementById('db_id').value = c.id;
    document.getElementById('db_name').value = c.name || '';
    document.getElementById('db_driver').value = c.driver || 'mysql';
    document.getElementById('db_host').value = c.host || 'localhost';
    document.getElementById('db_port').value = c.port || 3306;
    document.getElementById('db_database_name').value = c.database_name || '';
    document.getElementById('db_username').value = c.username || '';
    document.getElementById('db_password').value = '';
    document.getElementById('db_timeout').value = c.timeout_sec || 3;
    document.getElementById('db_active').checked = parseInt(c.is_active) === 1;
    document.getElementById('testFeedback').textContent = '';

    document.getElementById('dbFormBox').style.display = 'block';
    document.getElementById('dbFormBox').scrollIntoView({ behavior: 'smooth' });
}

function testFormConnection() {
    var feedback = document.getElementById('testFeedback');
    feedback.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + __('js.external_dbs.testing');

    var data = new URLSearchParams();
    data.append('csrf_token', <?php echo jsArg($csrf); ?>);
    data.append('action', 'test_connection');
    data.append('id', document.getElementById('db_id').value);
    data.append('driver', document.getElementById('db_driver').value);
    data.append('host', document.getElementById('db_host').value);
    data.append('port', document.getElementById('db_port').value);
    data.append('database_name', document.getElementById('db_database_name').value);
    data.append('username', document.getElementById('db_username').value);
    data.append('password', document.getElementById('db_password').value);
    data.append('timeout_sec', document.getElementById('db_timeout').value);

    fetch('/external-dbs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.ok) {
            feedback.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> ' + escapeHtml(res.message || '') + '</span>';
        } else {
            feedback.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> ' + escapeHtml(res.message || '') + '</span>';
        }
    })
    .catch(function(err) {
        feedback.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(err.message || '') + '</span>';
    });
}

function testSavedConnection(id) {
    var data = new URLSearchParams();
    data.append('csrf_token', <?php echo jsArg($csrf); ?>);
    data.append('action', 'test_connection');
    data.append('id', id);

    fetch('/external-dbs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: data.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        alert(res.ok ? __('js.external_dbs.test_ok', res.message) : __('js.external_dbs.test_failed', res.message));
        location.reload();
    })
    .catch(function(err) {
        alert(__('js.external_dbs.test_failed', err.message));
    });
}
</script>
