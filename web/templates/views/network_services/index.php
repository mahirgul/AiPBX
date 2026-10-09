<?php
/** @var string $csrf @var array $settings @var string $status @var array $interfaces @var array $leases
 *  @var array $tftpFiles @var array $tftpNetworks @var array $hints @var string $provisioningUrl */
$mode = $settings['netsvc_mode'];
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
            <label class="form-label"><?php echo t('netsvc.mode'); ?></label>
            <?php foreach (NetworkServicesService::MODES as $m): ?>
                <label class="u-check-label-6" style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="netsvc_mode" value="<?php echo $m; ?>" class="u-accent netsvc-mode" <?php echo $mode === $m ? 'checked' : ''; ?>>
                    <strong><?php echo t('netsvc.mode_' . $m); ?></strong>
                    <span class="u-muted u-fs-12">· <?php echo t('netsvc.mode_' . $m . '_desc'); ?></span>
                </label>
            <?php endforeach; ?>
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
            <label class="u-check-label-6" style="display: block;">
                <input type="checkbox" name="netsvc_tftp" value="1" class="u-accent" <?php echo $settings['netsvc_tftp'] === '1' ? 'checked' : ''; ?>>
                <?php echo t('netsvc.also_tftp'); ?>
            </label>
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
                                <form method="POST" autocomplete="off" class="u-inline" onsubmit="return confirm('<?php echo $h(t('netsvc.tftp_delete_confirm')); ?>');">
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
