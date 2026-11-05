<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<?php if (!empty($message)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $h($message); ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $h($error); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-map-marker-alt u-primary"></i> <?php echo t('phones.region_title'); ?></div>
        <div class="u-flex-gap">
            <a href="/phones" class="btn btn-secondary btn-sm" title="<?php echo t('phones.title'); ?>"><i class="fas fa-phone"></i></a>
            <button type="button" class="btn btn-primary btn-sm" onclick="phonesEditRegion(0)"><i class="fas fa-plus"></i> <?php echo t('phones.region_add'); ?></button>
        </div>
    </div>
    <p class="u-muted u-fs-12" style="margin: 0 20px 12px;"><?php echo t('phones.region_help'); ?></p>
    <div class="table-responsive"><table class="data-table">
        <thead><tr>
            <th><?php echo t('phones.region_name'); ?></th>
            <th><?php echo t('phones.region_sip_server'); ?></th>
            <th><?php echo t('phones.region_transport'); ?></th>
            <th><?php echo t('phones.region_col_phones'); ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($regions as $r): ?>
            <tr>
                <td><?php echo $h($r['name']); ?><?php if (!empty($r['is_default'])): ?> <span class="badge badge-secondary"><?php echo t('phones.region_default'); ?></span><?php endif; ?></td>
                <td><?php echo $r['sip_server'] !== '' ? $h($r['sip_server']) : '<span class="u-muted">' . t('phones.region_general') . '</span>'; ?></td>
                <td><?php echo $r['transport'] !== '' ? $h(strtoupper((string) $r['transport'])) : '<span class="u-muted">' . t('phones.region_general') . '</span>'; ?></td>
                <td><?php echo (int) ($phoneCounts[(int) $r['id']] ?? 0); ?></td>
                <td class="text-right" style="white-space: nowrap;">
                    <button type="button" class="btn btn-xs btn-secondary" onclick="phonesEditRegion(<?php echo (int) $r['id']; ?>)"><i class="fas fa-edit"></i></button>
                    <?php if (empty($r['is_default'])): ?>
                    <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(t('phones.region_delete_confirm'))); ?>);">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                        <input type="hidden" name="delete_region" value="1">
                        <input type="hidden" name="region_id" value="<?php echo (int) $r['id']; ?>">
                        <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="modal-overlay" id="phoneRegionModal">
    <div class="modal-card" style="max-width: 720px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-map-marker-alt u-primary"></i> <?php echo t('phones.region_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('phoneRegionModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off" id="phoneRegionForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="save_region" value="1">
                <input type="hidden" name="region_id" id="region_id" value="0">
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.region_name'); ?></label>
                    <input type="text" name="name" id="region_name" class="form-control" maxlength="50" required>
                </div>
                <div class="u-grid-2">
                    <?php foreach (['sip_server' => ['phones.region_sip_server', 'provision_sip_server'], 'sip_proxy' => ['phones.region_sip_proxy', 'provision_sip_proxy'], 'base_url' => ['phones.region_base_url', 'provision_base_url'], 'ntp' => ['phones.region_ntp', 'provision_ntp'], 'syslog' => ['phones.region_syslog', 'provision_syslog']] as $field => [$label, $key]): ?>
                    <div class="form-group">
                        <label class="form-label"><?php echo t($label); ?></label>
                        <input type="text" name="<?php echo $field; ?>" id="region_<?php echo $field; ?>" class="form-control" maxlength="255" placeholder="<?php echo htmlspecialchars((string) ($settings[$key] !== '' ? $settings[$key] : t('phones.region_general'))); ?>">
                    </div>
                    <?php endforeach; ?>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.region_sip_port'); ?></label>
                        <input type="number" name="sip_port" id="region_sip_port" class="form-control" min="1" max="65535" placeholder="<?php echo t('phones.region_general'); ?>">
                        <small class="u-hint"><?php echo t('phones.region_sip_port_help'); ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.region_transport'); ?></label>
                        <select name="transport" id="region_transport" class="form-control">
                            <option value=""><?php echo t('phones.region_general'); ?></option>
                            <option value="udp">UDP</option>
                            <option value="tcp">TCP</option>
                            <option value="tls">TLS</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.region_srtp'); ?></label>
                        <select name="srtp" id="region_srtp" class="form-control">
                            <option value=""><?php echo t('phones.region_general'); ?></option>
                            <option value="0"><?php echo t('phones.region_srtp_off'); ?></option>
                            <option value="1"><?php echo t('phones.region_srtp_optional'); ?></option>
                            <option value="2"><?php echo t('phones.region_srtp_required'); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.region_timezone'); ?></label>
                        <input type="text" name="timezone" id="region_timezone" class="form-control" list="regionTimezones" placeholder="<?php echo htmlspecialchars((string) $settings['provision_timezone']); ?>">
                        <datalist id="regionTimezones"><?php foreach (\DateTimeZone::listIdentifiers() as $tz): ?><option value="<?php echo htmlspecialchars($tz); ?>"></option><?php endforeach; ?></datalist>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.region_language'); ?></label>
                        <select name="language" id="region_language" class="form-control">
                            <option value=""><?php echo t('phones.region_general'); ?></option>
                            <?php foreach (UI_LANGUAGES as $code => $label): ?><option value="<?php echo htmlspecialchars((string) $code); ?>"><?php echo htmlspecialchars((string) (is_array($label) ? ($label['name'] ?? $code) : $label)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.region_codecs'); ?></label>
                    <div class="u-flex-gap">
                        <?php foreach ($codecs as $codec): ?><label class="u-fs-12"><input type="checkbox" name="codecs[]" class="region-codec" value="<?php echo htmlspecialchars((string) $codec); ?>"> <?php echo htmlspecialchars((string) $codec); ?></label><?php endforeach; ?>
                    </div>
                    <small class="u-hint"><?php echo t('phones.region_codecs_help'); ?></small>
                </div>
                <small class="u-hint"><?php echo t('phones.region_none_help'); ?></small>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('phoneRegionModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>
<script>window.PHONE_REGIONS = <?php echo json_encode($regions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo asset('/assets/js/phone_regions.js'); ?>"></script>
