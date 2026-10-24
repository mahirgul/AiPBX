<?php
/**
 * PBX -> IVR Data Steps (/ivr-data-steps)
 * Roadmap 2.3: IVR data lookup steps (database queries or HTTP requests).
 *
 * @var list<array<string, mixed>> $steps
 * @var list<array<string, mixed>> $dbs
 */
$csrf = getCSRFToken();
?>
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <a href="/ivrs" class="btn btn-secondary btn-sm u-mr-8" title="<?php echo htmlspecialchars(t('common.back', 'Back to IVRs')); ?>">
                <i class="fas fa-arrow-left"></i>
            </a>
            <i class="fas fa-database u-primary"></i> <?php echo htmlspecialchars(t('ivr_data.page_title', 'IVR Data Steps')); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($steps); ?></span>
        </div>
        <div class="u-flex-center" style="gap: 8px;">
            <button type="button" class="btn btn-primary btn-sm" onclick="showNewStepForm()">
                <i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars(t('ivr_data.btn_new', 'New Data Step')); ?>
            </button>
        </div>
    </div>

    <div class="module-help-box" style="display: block; margin: 16px 20px 20px;">
        <h4><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('ivr_data.help_title', 'Interactive Data Steps in IVRs')); ?></h4>
        <p style="margin: 4px 0 0;"><?php echo htmlspecialchars(t('ivr_data.help_body', 'Run a fast database query or HTTP request during an incoming call to look up caller data. Results are stored in Asterisk channel variables, and calls branch seamlessly to Found, Not Found, or Timeout destinations.')); ?></p>
    </div>

    <!-- Create / Edit Form Box -->
    <div id="stepFormBox" style="display: none; padding: 20px; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); background: var(--card-bg, #fff);">
        <h4 id="stepFormTitle" style="margin-top: 0; margin-bottom: 16px;">
            <i class="fas fa-plus"></i> <?php echo htmlspecialchars(t('ivr_data.btn_new', 'New Data Step')); ?>
        </h4>
        <form method="POST" id="stepForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="save_step">
            <input type="hidden" name="id" id="step_id" value="">

            <div class="u-grid-2" style="gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" for="step_name"><strong><?php echo htmlspecialchars(t('ivr_data.name', 'Step Name')); ?> *</strong></label>
                    <input type="text" name="name" id="step_name" class="form-control" required maxlength="100" placeholder="<?php echo htmlspecialchars(t('ivr_data.ph_name')); ?>">
                </div>
                <div>
                    <label class="form-label" for="step_channel_var"><strong><?php echo htmlspecialchars(t('ivr_data.channel_var', 'Channel Variable Name')); ?> *</strong></label>
                    <input type="text" name="channel_var_name" id="step_channel_var" class="form-control" required value="IVR_DATA_VAL" placeholder="IVR_DATA_VAL">
                </div>
            </div>

            <div class="u-grid-2" style="gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" for="step_input_source"><strong><?php echo htmlspecialchars(t('ivr_data.input_source', 'Input Source')); ?></strong></label>
                    <select name="input_source" id="step_input_source" class="form-control" onchange="toggleInputFields(this.value)">
                        <option value="dtmf"><?php echo htmlspecialchars(t('ivr_data.input_dtmf')); ?></option>
                        <option value="caller_id"><?php echo htmlspecialchars(t('ivr_data.input_callerid')); ?></option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="step_data_source"><strong><?php echo htmlspecialchars(t('ivr_data.data_source', 'Data Source')); ?></strong></label>
                    <select name="data_source" id="step_data_source" class="form-control" onchange="toggleDataSourceFields(this.value)">
                        <option value="database"><?php echo htmlspecialchars(t('ivr_data.source_db')); ?></option>
                        <option value="http"><?php echo htmlspecialchars(t('ivr_data.source_http')); ?></option>
                    </select>
                </div>
            </div>

            <!-- DTMF Specific Inputs -->
            <div id="dtmfBox" class="u-grid-2" style="gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" for="step_prompt"><strong><?php echo htmlspecialchars(t('ivr_data.prompt_audio', 'Audio Prompt (Optional)')); ?></strong></label>
                    <input type="text" name="prompt_file" id="step_prompt" class="form-control" placeholder="custom/enter_customer_num">
                </div>
                <div>
                    <label class="form-label"><strong><?php echo htmlspecialchars(t('ivr_data.dtmf_limits', 'Input Limits')); ?></strong></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" name="max_digits" id="step_max_digits" class="form-control" value="10" min="1" max="30" placeholder="<?php echo htmlspecialchars(t('ivr_data.ph_max_digits')); ?>">
                        <input type="number" name="input_timeout_sec" id="step_input_timeout" class="form-control" value="5" min="1" max="30" placeholder="<?php echo htmlspecialchars(t('ivr_data.ph_timeout')); ?>">
                    </div>
                </div>
            </div>

            <!-- Database Specific Inputs -->
            <div id="dbSourceBox" style="margin-bottom: 16px;">
                <div style="margin-bottom: 12px;">
                    <label class="form-label" for="step_db_id"><strong><?php echo htmlspecialchars(t('ivr_data.external_db', 'Database Connection')); ?> *</strong></label>
                    <select name="external_db_id" id="step_db_id" class="form-control">
                        <option value="">— Select Database Connection —</option>
                        <?php foreach ($dbs as $dbItem): ?>
                            <option value="<?php echo (int)$dbItem['id']; ?>">
                                <?php echo htmlspecialchars((string)$dbItem['name']); ?> (<?php echo htmlspecialchars((string)$dbItem['driver']); ?>: <?php echo htmlspecialchars((string)$dbItem['database_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="step_query_sql"><strong><?php echo htmlspecialchars(t('ivr_data.query_sql', 'SQL Query (Read-Only SELECT)')); ?> *</strong></label>
                    <textarea name="query_sql" id="step_query_sql" class="form-control" rows="3" style="font-family: monospace;" placeholder="SELECT is_vip FROM customers WHERE phone = :input OR phone = :caller_id LIMIT 1"></textarea>
                    <div class="u-muted u-fs-11" style="margin-top: 4px;"><?php echo htmlspecialchars(t('ivr_data.placeholders')); ?> <code>:input</code>, <code>:caller_id</code></div>
                </div>
            </div>

            <!-- HTTP Specific Inputs -->
            <div id="httpSourceBox" style="display: none; margin-bottom: 16px;">
                <div class="u-grid-2" style="gap: 16px;">
                    <div>
                        <label class="form-label" for="step_http_url"><strong><?php echo htmlspecialchars(t('ivr_data.http_url', 'HTTP API URL')); ?> *</strong></label>
                        <input type="url" name="http_url" id="step_http_url" class="form-control" placeholder="https://api.example.com/customers/lookup?phone={input}">
                        <div class="u-muted u-fs-11" style="margin-top: 4px;"><?php echo htmlspecialchars(t('ivr_data.placeholders')); ?> <code>{input}</code>, <code>{caller_id}</code></div>
                    </div>
                    <div>
                        <label class="form-label" for="step_http_method"><strong><?php echo htmlspecialchars(t('ivr_data.http_method', 'HTTP Method')); ?></strong></label>
                        <select name="http_method" id="step_http_method" class="form-control">
                            <option value="GET">GET</option>
                            <option value="POST"><?php echo htmlspecialchars(t('ivr_data.method_post')); ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Branches -->
            <div style="margin-bottom: 16px; padding: 14px; background: var(--bg-subtle, rgba(2,132,199,0.04)); border: 1px solid var(--border-color); border-radius: var(--radius-sm, 6px);">
                <div style="font-weight: 700; margin-bottom: 12px;"><i class="fas fa-code-branch u-primary"></i> <?php echo htmlspecialchars(t('ivr_data.branches', 'Call Routing Branches')); ?></div>
                <div class="u-grid-3" style="gap: 16px;">
                    <div>
                        <label class="form-label"><strong><i class="fas fa-check text-success"></i> <?php echo htmlspecialchars(t('ivr_data.branch_found', 'Found (Match)')); ?></strong></label>
                        <input type="text" name="found_dest_type" id="step_found_dest_type" class="form-control form-control-sm u-mb-4" placeholder="dest_type (e.g. queue)">
                        <input type="text" name="found_dest_id" id="step_found_dest_id" class="form-control form-control-sm" placeholder="dest_id (e.g. queue_cc)">
                    </div>
                    <div>
                        <label class="form-label"><strong><i class="fas fa-times text-warning"></i> <?php echo htmlspecialchars(t('ivr_data.branch_not_found', 'Not Found')); ?></strong></label>
                        <input type="text" name="not_found_dest_type" id="step_not_found_dest_type" class="form-control form-control-sm u-mb-4" placeholder="dest_type (e.g. ivr)">
                        <input type="text" name="not_found_dest_id" id="step_not_found_dest_id" class="form-control form-control-sm" placeholder="dest_id">
                    </div>
                    <div>
                        <label class="form-label"><strong><i class="fas fa-clock text-danger"></i> <?php echo htmlspecialchars(t('ivr_data.branch_timeout', 'Timeout / Error')); ?></strong></label>
                        <input type="text" name="timeout_dest_type" id="step_timeout_dest_type" class="form-control form-control-sm u-mb-4" placeholder="dest_type (e.g. hangup)">
                        <input type="text" name="timeout_dest_id" id="step_timeout_dest_id" class="form-control form-control-sm" placeholder="dest_id">
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="u-check-label u-fs-13">
                    <input type="checkbox" name="is_active" id="step_active" value="1" checked class="u-accent">
                    <?php echo htmlspecialchars(t('common.active', 'Active')); ?>
                </label>
            </div>

            <div class="u-flex-gap" style="justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('stepFormBox').style.display='none';">
                    <?php echo htmlspecialchars(t('common.cancel', 'Cancel')); ?>
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('common.save', 'Save Data Step')); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Steps Table -->
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('ivr_data.name', 'Step')); ?></th>
                    <th><?php echo htmlspecialchars(t('ivr_data.input_source', 'Input')); ?></th>
                    <th><?php echo htmlspecialchars(t('ivr_data.data_source', 'Source')); ?></th>
                    <th><?php echo htmlspecialchars(t('ivr_data.channel_var', 'Variable')); ?></th>
                    <th><?php echo htmlspecialchars(t('common.status', 'Status')); ?></th>
                    <th class="text-right"><?php echo htmlspecialchars(t('common.actions', 'Actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($steps)): ?>
                    <tr>
                        <td colspan="6" class="text-center u-muted" style="padding: 30px;">
                            <i class="fas fa-database fa-2x u-mb-8" style="display: block; opacity: 0.5;"></i>
                            <?php echo htmlspecialchars(t('ivr_data.empty', 'No IVR data steps configured yet.')); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($steps as $s): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$s['name']); ?></strong>
                                <?php if (!empty($s['description'])): ?>
                                    <div class="u-muted u-fs-11"><?php echo htmlspecialchars((string)$s['description']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-secondary u-fs-11"><?php echo htmlspecialchars((string)$s['input_source']); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-info u-fs-11"><?php echo htmlspecialchars((string)$s['data_source']); ?></span>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars((string)$s['channel_var_name']); ?></code>
                            </td>
                            <td>
                                <?php if (!empty($s['is_active'])): ?>
                                    <span class="badge badge-success"><?php echo htmlspecialchars(t('common.active', 'Active')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars(t('common.passive', 'Disabled')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <button type="button" class="btn btn-secondary btn-sm" onclick='editStep(<?php echo json_encode($s, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)' title="<?php echo htmlspecialchars(t('common.edit', 'Edit')); ?>">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm(<?php echo jsArg(t('ivr_data.confirm_delete', 'Are you sure you want to delete this data step?')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="action" value="delete_step">
                                    <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
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
function toggleInputFields(val) {
    document.getElementById('dtmfBox').style.display = (val === 'dtmf') ? 'grid' : 'none';
}

function toggleDataSourceFields(val) {
    document.getElementById('dbSourceBox').style.display = (val === 'database') ? 'block' : 'none';
    document.getElementById('httpSourceBox').style.display = (val === 'http') ? 'block' : 'none';
}

function showNewStepForm() {
    document.getElementById('stepFormTitle').innerHTML = '<i class="fas fa-plus"></i> ' + <?php echo jsArg(t('ivr_data.btn_new', 'New Data Step')); ?>;
    document.getElementById('stepForm').reset();
    document.getElementById('step_id').value = '';
    document.getElementById('step_channel_var').value = 'IVR_DATA_VAL';
    document.getElementById('step_max_digits').value = '10';
    document.getElementById('step_input_timeout').value = '5';
    document.getElementById('step_found_dest_type').value = 'queue';
    document.getElementById('step_not_found_dest_type').value = 'hangup';
    document.getElementById('step_timeout_dest_type').value = 'hangup';
    document.getElementById('step_active').checked = true;
    toggleInputFields('dtmf');
    toggleDataSourceFields('database');
    document.getElementById('stepFormBox').style.display = 'block';
    document.getElementById('stepFormBox').scrollIntoView({ behavior: 'smooth' });
}

function editStep(s) {
    document.getElementById('stepFormTitle').innerHTML = '<i class="fas fa-pencil-alt"></i> ' + <?php echo jsArg(t('common.edit', 'Edit Data Step')); ?> + ': ' + escapeHtml(s.name || '');
    document.getElementById('step_id').value = s.id;
    document.getElementById('step_name').value = s.name || '';
    document.getElementById('step_channel_var').value = s.channel_var_name || 'IVR_DATA_VAL';
    document.getElementById('step_input_source').value = s.input_source || 'dtmf';
    document.getElementById('step_data_source').value = s.data_source || 'database';
    document.getElementById('step_prompt').value = s.prompt_file || '';
    document.getElementById('step_max_digits').value = s.max_digits || 10;
    document.getElementById('step_input_timeout').value = s.input_timeout_sec || 5;
    document.getElementById('step_db_id').value = s.external_db_id || '';
    document.getElementById('step_query_sql').value = s.query_sql || '';
    document.getElementById('step_http_url').value = s.http_url || '';
    document.getElementById('step_http_method').value = s.http_method || 'GET';
    document.getElementById('step_found_dest_type').value = s.found_dest_type || 'queue';
    document.getElementById('step_found_dest_id').value = s.found_dest_id || '';
    document.getElementById('step_not_found_dest_type').value = s.not_found_dest_type || 'hangup';
    document.getElementById('step_not_found_dest_id').value = s.not_found_dest_id || '';
    document.getElementById('step_timeout_dest_type').value = s.timeout_dest_type || 'hangup';
    document.getElementById('step_timeout_dest_id').value = s.timeout_dest_id || '';
    document.getElementById('step_active').checked = parseInt(s.is_active) === 1;

    toggleInputFields(s.input_source || 'dtmf');
    toggleDataSourceFields(s.data_source || 'database');

    document.getElementById('stepFormBox').style.display = 'block';
    document.getElementById('stepFormBox').scrollIntoView({ behavior: 'smooth' });
}
</script>
