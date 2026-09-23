<!-- Global Fax Email & Notification Settings Card -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-paper-plane" style="color: var(--primary);"></i> <?php echo t('fax_mail_settings.title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('faxMailHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="faxMailHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('fax_mail_settings.help_title'); ?></h4>
        <?php echo t('fax_mail_settings.help_body'); ?>
    </div>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
        <input type="hidden" name="save_fax_email_settings" value="1">

        <!-- Yatay Sekmeler (Sabit Tek Satır) -->
        <div class="settings-tabs" style="margin-bottom: 20px;">
            <button type="button" class="settings-tab-btn active" data-tab="email" onclick="switchSettingsTab('email', this)">
                <i class="fas fa-envelope"></i> <?php echo t('fax_mail_settings.tab_email', 'E-Posta'); ?>
            </button>
            <button type="button" class="settings-tab-btn" data-tab="device" onclick="switchSettingsTab('device', this)">
                <i class="fas fa-fax"></i> <?php echo t('fax_mail_settings.tab_device', 'Cihaz'); ?>
            </button>
        </div>

        <!-- TAB 1: E-Posta & Bildirim -->
        <div class="settings-tab-pane active" id="tab_email">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_from_address'); ?></label>
                    <input type="email" name="fax_email_from_address" class="form-control" value="<?php echo htmlspecialchars($fax_from_addr); ?>" required>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('fax_mail_settings.from_address_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_from_name'); ?></label>
                    <input type="text" name="fax_email_from_name" class="form-control" value="<?php echo htmlspecialchars($fax_from_name); ?>" required>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('fax_mail_settings.from_name_help'); ?></small>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_rx_enabled'); ?></label>
                    <select name="fax_email_rx_enabled" class="form-control">
                        <option value="yes" <?php echo $fax_rx_enabled === 'yes' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.rx_enabled_yes'); ?></option>
                        <option value="no" <?php echo $fax_rx_enabled === 'no' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.rx_enabled_no'); ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_rx_attach'); ?></label>
                    <select name="fax_email_rx_attach_pdf" class="form-control">
                        <option value="yes" <?php echo $fax_rx_attach === 'yes' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.rx_attach_yes'); ?></option>
                        <option value="no" <?php echo $fax_rx_attach === 'no' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.rx_attach_no'); ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_tx_enabled'); ?></label>
                    <select name="fax_email_tx_enabled" class="form-control">
                        <option value="yes" <?php echo $fax_tx_enabled === 'yes' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.tx_enabled_yes'); ?></option>
                        <option value="no" <?php echo $fax_tx_enabled === 'no' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.tx_enabled_no'); ?></option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_retention'); ?></label>
                    <select name="fax_retention_days" class="form-control">
                        <option value="30" <?php echo $fax_retention === '30' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.retention_30'); ?></option>
                        <option value="60" <?php echo $fax_retention === '60' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.retention_60'); ?></option>
                        <option value="90" <?php echo $fax_retention === '90' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.retention_90'); ?></option>
                        <option value="365" <?php echo $fax_retention === '365' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.retention_365'); ?></option>
                        <option value="0" <?php echo $fax_retention === '0' ? 'selected' : ''; ?>><?php echo t('fax_mail_settings.retention_unlimited'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- TAB 2: Cihaz & İletim -->
        <div class="settings-tab-pane" id="tab_device" style="display: none;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_header_info'); ?></label>
                    <input type="text" name="fax_header_info" class="form-control" value="<?php echo htmlspecialchars($fax_header_info); ?>" required>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('fax_mail_settings.header_info_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_station_id'); ?></label>
                    <input type="text" name="fax_local_station_id" class="form-control" value="<?php echo htmlspecialchars($fax_station_id); ?>" required maxlength="20">
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('fax_mail_settings.station_id_help'); ?></small>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_max_retries'); ?></label>
                    <input type="number" name="fax_max_retries" class="form-control" value="<?php echo htmlspecialchars($fax_max_retries); ?>" min="1" max="10" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_retry_time'); ?></label>
                    <input type="number" name="fax_retry_time" class="form-control" value="<?php echo htmlspecialchars($fax_retry_time); ?>" min="10" max="300" required>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('fax_mail_settings.retry_time_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('fax_mail_settings.field_wait_time'); ?></label>
                    <input type="number" name="fax_wait_time" class="form-control" value="<?php echo htmlspecialchars($fax_wait_time); ?>" min="5" max="120" required>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('fax_mail_settings.wait_time_help'); ?></small>
                </div>
            </div>
        </div>

        <?php if (hasModulePermission('fax_mail_settings', 'edit')): ?>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-top: 20px;" title="<?php echo t('fax_mail_settings.save_tooltip'); ?>">
                <i class="fas fa-save"></i> <?php echo t('common.save', 'Kaydet'); ?>
            </button>
        <?php endif; ?>
    </form>
</div>
