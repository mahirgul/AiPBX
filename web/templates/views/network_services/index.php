<?php
/** @var string $csrf @var array $settings @var string $status @var array $interfaces @var array $leases
 *  @var array $tftpFiles @var array $tftpNetworks @var array $hints @var string $provisioningUrl @var string $ftpStatus @var string $syslogStatus @var string $tftpDir @var string $ntpStatus @var string $serverIp */
$mode = $settings['netsvc_mode'];
// Two switches (DHCP, TFTP) stored as one mode: dhcp (TFTP optional), tftp (TFTP only) or off.
$tftpOn = $mode === 'tftp' || ($mode === 'dhcp' && $settings['netsvc_tftp'] === '1');
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-ethernet u-primary"></i> <?php echo t('netsvc.header_title'); ?>
            <span class="badge <?php echo $status === 'active' ? 'badge-success' : 'badge-warning'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo $mode === 'off' ? t('netsvc.mode_off') : ($status === 'active' ? t('netsvc.status_active') : t('netsvc.status_inactive')); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('netsvcHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="netsvcHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('netsvc.help_title'); ?></h4>
        <?php echo t('netsvc.help_body'); ?>
    </div>

    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;" id="netsvcForm">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">

        <div class="form-group">
            <label class="form-label"><?php echo t('netsvc.services'); ?></label>
            <label class="u-check-label-6" style="display: block; margin-bottom: 4px;">
                <input type="checkbox" name="netsvc_dhcp_on" value="1" class="u-accent netsvc-switch" id="netsvc_dhcp_on" <?php echo $mode === 'dhcp' ? 'checked' : ''; ?>>
                <strong><?php echo t('netsvc.switch_dhcp'); ?></strong>
                <span class="u-muted u-fs-12">· <?php echo t('netsvc.mode_dhcp_desc'); ?></span>
            </label>
            <label class="u-check-label-6" style="display: block; margin-bottom: 4px;">
                <input type="checkbox" name="netsvc_tftp" value="1" class="u-accent netsvc-switch" id="netsvc_tftp_on" <?php echo $tftpOn ? 'checked' : ''; ?>>
                <strong><?php echo t('netsvc.switch_tftp'); ?></strong>
                <span class="u-muted u-fs-12">· <?php echo t('netsvc.switch_tftp_desc'); ?></span>
                <span class="u-fs-12 u-warning" style="display: block; margin-left: 24px;"><i class="fas fa-triangle-exclamation"></i> <?php echo t('netsvc.tftp_warning'); ?></span>
            </label>
            <label class="u-check-label-6" style="display: block; margin-bottom: 4px;">
                <input type="checkbox" name="netsvc_ftp" value="1" class="u-accent netsvc-switch" id="netsvc_ftp_on" <?php echo $settings['netsvc_ftp'] === '1' ? 'checked' : ''; ?>>
                <strong><?php echo t('netsvc.switch_ftp'); ?></strong>
                <span class="u-muted u-fs-12">· <?php echo t('netsvc.switch_ftp_desc'); ?></span>
                <?php if ($settings['netsvc_ftp'] === '1'): ?>
                    <span class="badge <?php echo $ftpStatus === 'active' ? 'badge-success' : 'badge-warning'; ?>" style="font-size: 11px;"><?php echo $ftpStatus === 'active' ? t('netsvc.status_active') : t('netsvc.status_inactive'); ?></span>
                <?php endif; ?>
            </label>
            <label class="u-check-label-6" style="display: block; margin-bottom: 4px;">
                <input type="checkbox" name="netsvc_syslog" value="1" class="u-accent netsvc-switch" id="netsvc_syslog_on" <?php echo $settings['netsvc_syslog'] === '1' ? 'checked' : ''; ?>>
                <strong><?php echo t('netsvc.switch_syslog'); ?></strong>
                <span class="u-muted u-fs-12">· <?php echo t('netsvc.switch_syslog_desc'); ?> <a href="/phone-logs"><?php echo t('sidebar.item_phone_logs'); ?></a></span>
                <?php if ($settings['netsvc_syslog'] === '1'): ?>
                    <span class="badge <?php echo $syslogStatus === 'active' ? 'badge-success' : 'badge-warning'; ?>" style="font-size: 11px;"><?php echo $syslogStatus === 'active' ? t('netsvc.status_active') : t('netsvc.status_inactive'); ?></span>
                <?php endif; ?>
            </label>
            <label class="u-check-label-6" style="display: block; margin-bottom: 4px;">
                <input type="checkbox" name="netsvc_ntp_server" value="1" class="u-accent netsvc-switch" id="netsvc_ntp_server_on" <?php echo $settings['netsvc_ntp_server'] === '1' ? 'checked' : ''; ?>>
                <strong><?php echo t('netsvc.switch_ntp_server'); ?></strong>
                <span class="u-muted u-fs-12">· <?php echo sprintf(t('netsvc.switch_ntp_server_desc'), $h($serverIp)); ?></span>
                <?php if ($settings['netsvc_ntp_server'] === '1'): ?>
                    <span class="badge <?php echo $ntpStatus === 'active' ? 'badge-success' : 'badge-warning'; ?>" style="font-size: 11px;"><?php echo $ntpStatus === 'active' ? t('netsvc.status_active') : t('netsvc.status_inactive'); ?></span>
                <?php endif; ?>
            </label>
        </div>

        <div class="netsvc-ftp">
            <p class="u-fs-12" style="margin: 0 0 8px;"><?php echo sprintf(t('netsvc.ftp_fixed_user'), '<code>' . NetworkServicesService::FTP_FIXED_USER . '</code>', '<code>' . NetworkServicesService::FTP_FIXED_DEFAULT_PASSWORD . '</code>'); ?>
                <span class="badge <?php echo $settings['netsvc_ftp_fixed_hash'] === '' ? 'badge-warning' : 'badge-success'; ?>" style="font-size: 11px;"><?php echo t($settings['netsvc_ftp_fixed_hash'] === '' ? 'netsvc.ftp_fixed_is_default' : 'netsvc.ftp_fixed_is_custom'); ?></span></p>
            <div class="form-group" style="max-width: 360px;">
                <label class="form-label"><?php echo sprintf(t('netsvc.ftp_fixed_password'), NetworkServicesService::FTP_FIXED_USER); ?></label>
                <input type="password" name="netsvc_ftp_fixed_password" class="form-control" maxlength="64" autocomplete="new-password" placeholder="<?php echo $h(t('netsvc.ftp_fixed_keep')); ?>">
            </div>
            <label class="u-check-label-6" style="display: block; margin-bottom: 8px;">
                <input type="checkbox" name="netsvc_ftp_anonymous" value="1" class="u-accent" <?php echo $settings['netsvc_ftp_anonymous'] === '1' ? 'checked' : ''; ?>>
                <strong><?php echo t('netsvc.ftp_anonymous'); ?></strong>
                <span class="u-muted u-fs-12">· <?php echo t('netsvc.ftp_anonymous_desc'); ?></span>
            </label>
            <?php $ftpUsers = NetworkServicesService::ftpUsers($settings['netsvc_ftp_users']); ?>
            <?php if ($ftpUsers !== []): ?>
                <label class="form-label"><?php echo t('netsvc.ftp_users'); ?></label>
                <div style="margin-bottom: 8px;">
                    <?php foreach ($ftpUsers as $u): ?>
                        <label class="u-check-label-6" style="display: block;">
                            <input type="checkbox" name="netsvc_ftp_remove[]" value="<?php echo $h($u['user']); ?>" class="u-accent">
                            <code><?php echo $h($u['user']); ?></code> <span class="u-muted u-fs-12">· <?php echo t('netsvc.ftp_remove'); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.ftp_new_user'); ?></label>
                    <input type="text" name="netsvc_ftp_new_user" class="form-control" maxlength="32" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.ftp_password'); ?></label>
                    <input type="password" name="netsvc_ftp_new_password" class="form-control" maxlength="64" autocomplete="new-password">
                </div>
            </div>
            <small class="u-hint u-fs-12"><?php echo t('netsvc.ftp_help'); ?></small>
        </div>

        <div class="form-group netsvc-needs-iface">
            <label class="form-label"><?php echo t('netsvc.interface'); ?></label>
            <select name="netsvc_interface" class="form-control">
                <?php if ($interfaces === []): ?>
                    <option value=""><?php echo t('netsvc.no_interfaces'); ?></option>
                <?php endif; ?>
                <?php foreach ($interfaces as $name => $if): ?>
                    <option value="<?php echo $h($name); ?>" <?php echo $settings['netsvc_interface'] === $name ? 'selected' : ''; ?>>
                        <?php echo $h($name . ' (' . $if['ip'] . '/' . $if['prefix'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="netsvc-dhcp">
            <p style="color: var(--warning); margin: 0 0 12px;">
                <i class="fas fa-triangle-exclamation"></i> <?php echo t('netsvc.dhcp_warning'); ?>
            </p>
            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.range_start'); ?></label>
                    <input type="text" name="netsvc_range_start" class="form-control" value="<?php echo $h($settings['netsvc_range_start']); ?>" placeholder="192.168.10.100">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.range_end'); ?></label>
                    <input type="text" name="netsvc_range_end" class="form-control" value="<?php echo $h($settings['netsvc_range_end']); ?>" placeholder="192.168.10.199">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.netmask'); ?></label>
                    <input type="text" name="netsvc_netmask" class="form-control" value="<?php echo $h($settings['netsvc_netmask']); ?>" placeholder="255.255.255.0">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.gateway'); ?></label>
                    <input type="text" name="netsvc_gateway" class="form-control" value="<?php echo $h($settings['netsvc_gateway']); ?>" placeholder="192.168.10.1">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.dns'); ?></label>
                    <input type="text" name="netsvc_dns" class="form-control" value="<?php echo $h($settings['netsvc_dns']); ?>" placeholder="192.168.10.1, 1.1.1.1">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.ntp'); ?></label>
                    <input type="text" name="netsvc_ntp" class="form-control" value="<?php echo $h($settings['netsvc_ntp']); ?>" placeholder="192.168.10.1">
                    <small class="u-hint u-fs-11"><?php echo t('netsvc.ntp_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.lease_hours'); ?></label>
                    <input type="number" min="1" max="168" name="netsvc_lease_hours" class="form-control" value="<?php echo $h($settings['netsvc_lease_hours']); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netsvc.option66'); ?></label>
                    <input type="text" class="form-control" value="<?php echo $h($provisioningUrl); ?>" readonly>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('netsvc.dhcp_options'); ?></label>
                <textarea name="netsvc_dhcp_options" class="form-control" rows="3" spellcheck="false" style="font-family: monospace;"
                          placeholder="66,ftp://192.168.10.2/&#10;43,0a:04:c0:a8:0a:02"><?php echo $h($settings['netsvc_dhcp_options']); ?></textarea>
                <small class="u-hint u-fs-11"><?php echo t('netsvc.dhcp_options_help'); ?></small>
            </div>
            <label class="u-check-label-6" style="display: block;">
                <input type="checkbox" name="netsvc_confirm" value="1" class="u-accent" <?php echo $mode === 'dhcp' ? 'checked' : ''; ?>>
                <?php echo t('netsvc.confirm'); ?>
            </label>
            <label class="u-check-label-6" style="display: block;">
                <input type="checkbox" name="netsvc_force" value="1" class="u-accent">
                <?php echo t('netsvc.force'); ?>
            </label>
        </div>

        <div class="netsvc-tftp">
            <small class="u-hint u-fs-12">
                <?php echo $tftpNetworks === []
                    ? t('netsvc.tftp_no_networks')
                    : sprintf(t('netsvc.tftp_networks'), $h(implode(', ', $tftpNetworks))); ?>
                <a href="/phones"><?php echo t('netsvc.phones_settings_link'); ?></a>
            </small>
        </div>

        <div style="display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap;">
            <button type="submit" name="save_netsvc" value="1" class="btn btn-primary"><i class="fas fa-check"></i> <?php echo t('netsvc.save'); ?></button>
            <button type="submit" name="probe_dhcp" value="1" class="btn btn-secondary netsvc-needs-iface"><i class="fas fa-magnifying-glass"></i> <?php echo t('netsvc.probe'); ?></button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-list-check u-primary"></i> <?php echo t('netsvc.hints_title'); ?></div>
    </div>
    <div style="padding: 0 20px 8px;" class="u-muted u-fs-12"><?php echo t('netsvc.hints_help'); ?></div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('netsvc.col_option'); ?></th>
                    <th><?php echo t('netsvc.col_value'); ?></th>
                    <th><?php echo t('netsvc.col_for'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hints as $row): ?>
                    <tr>
                        <td class="u-fw-700"><?php echo $h($row['option']); ?></td>
                        <td style="font-family: monospace;"><?php echo $h($row['value']); ?></td>
                        <td class="u-muted u-fs-12"><?php echo $h($row['for']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-address-book u-primary"></i> <?php echo t('netsvc.leases_title'); ?></div>
    </div>
    <div style="padding: 0 20px 8px;" class="u-muted u-fs-12"><?php echo t('netsvc.leases_help'); ?></div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('netsvc.col_mac'); ?></th>
                    <th><?php echo t('netsvc.col_ip'); ?></th>
                    <th><?php echo t('netsvc.col_hostname'); ?></th>
                    <th><?php echo t('netsvc.col_vendor'); ?></th>
                    <th><?php echo t('netsvc.col_expires'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($leases === []): ?>
                    <?php echo uiTableEmptyRow(5, t('netsvc.leases_empty'), 'fa-address-book'); ?>
                <?php else: ?>
                    <?php foreach ($leases as $l): ?>
                        <tr>
                            <td style="font-family: monospace;"><?php echo $h(PhoneModels::formatMac($l['mac'])); ?></td>
                            <td><?php echo $h($l['ip']); ?></td>
                            <td><?php echo $h($l['hostname']); ?></td>
                            <td><?php echo $l['vendor'] !== '' ? '<span class="badge badge-info">' . $h(PhoneModels::VENDORS[$l['vendor']] ?? $l['vendor']) . '</span>' : ''; ?></td>
                            <td class="u-muted u-fs-12"><?php echo $l['expires'] > 0 ? $h(date('Y-m-d H:i', $l['expires'])) : t('netsvc.never'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-folder-open u-primary"></i> <?php echo t('netsvc.tftp_title'); ?></div>
    </div>
    <div style="padding: 0 20px 4px;" class="u-fs-12"><i class="fas fa-folder u-muted"></i> <?php echo sprintf(t('netsvc.tftp_folder'), '<code>' . $h($tftpDir) . '</code>'); ?></div>
    <div style="padding: 0 20px 8px;" class="u-muted u-fs-12"><?php echo t('netsvc.tftp_help'); ?></div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('netsvc.col_file'); ?></th>
                    <th><?php echo t('netsvc.col_size'); ?></th>
                    <th class="u-text-right"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($tftpFiles === []): ?>
                    <?php echo uiTableEmptyRow(3, t('netsvc.tftp_empty'), 'fa-folder-open'); ?>
                <?php else: ?>
                    <?php foreach ($tftpFiles as $f): ?>
                        <tr>
                            <td style="font-family: monospace;"><?php echo $h($f['name']); ?></td>
                            <td class="u-muted u-fs-12"><?php echo $h(number_format($f['size'] / 1024, 1) . ' KB'); ?></td>
                            <td class="u-text-right">
                                <form method="POST" autocomplete="off" class="u-inline" onsubmit="return confirm(<?php echo jsArg(t('netsvc.tftp_delete_confirm')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                                    <input type="hidden" name="delete_tftp" value="1">
                                    <input type="hidden" name="name" value="<?php echo $h($f['name']); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" enctype="multipart/form-data" autocomplete="off" style="padding: 12px 20px 20px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="upload_tftp" value="1">
        <input type="file" name="tftp_file" class="form-control" style="max-width: 360px;" required>
        <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> <?php echo t('netsvc.tftp_upload'); ?></button>
    </form>
</div>

<script src="<?php echo asset('/assets/js/network_services.js'); ?>"></script>
