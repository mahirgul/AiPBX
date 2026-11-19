<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$cfg = $config ?? [];
$isActive = !empty($cfg['is_active']);
$timeoutDestType = (string) ($cfg['timeout_dest_type'] ?? '');
$timeoutDestId = (string) ($cfg['timeout_dest_id'] ?? '');
?>

<?php echo uiPageHeader('fa-parking', t('parking.title'), 'parkingHelpBox'); ?>

<?php echo uiHelpBoxOpen('parkingHelpBox', t('common.module_guide')); ?>
    <?php echo $h(t('parking.help_body')); ?>
<?php echo uiHelpBoxClose(); ?>

<div class="grid grid-cols-1 lg:grid-cols-3 u-gap-16">
    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-sliders-h u-primary"></i> <?php echo $h(t('parking.settings_title')); ?></h2>
            </div>
            <div class="card-body">
                <form method="POST" action="/parking" data-no-spa="true">
                    <input type="hidden" name="csrf_token" value="<?php echo $h(getCSRFToken()); ?>">
                    <input type="hidden" name="save_parking" value="1">

                    <div class="form-group u-mb-16">
                        <label class="form-label u-check-label">
                            <input type="checkbox" name="is_active" value="1" <?php echo $isActive ? 'checked' : ''; ?> class="u-check">
                            <span><strong><?php echo $h(t('parking.field_active')); ?></strong></span>
                        </label>
                        <small class="u-hint"><?php echo $h(t('parking.field_active_help')); ?></small>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 u-gap-12">
                        <div class="form-group">
                            <label class="form-label" for="pk_parkext"><?php echo $h(t('parking.field_parkext')); ?></label>
                            <input type="text" id="pk_parkext" name="parkext" class="form-control" value="<?php echo $h($cfg['parkext'] ?? '700'); ?>" pattern="[0-9]{2,8}" required>
                            <small class="u-hint"><?php echo $h(t('parking.field_parkext_help')); ?></small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pk_slot_start"><?php echo $h(t('parking.field_slot_start')); ?></label>
                            <input type="number" id="pk_slot_start" name="slot_start" class="form-control" min="10" max="99999999" value="<?php echo (int) ($cfg['slot_start'] ?? 701); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pk_slot_end"><?php echo $h(t('parking.field_slot_end')); ?></label>
                            <input type="number" id="pk_slot_end" name="slot_end" class="form-control" min="10" max="99999999" value="<?php echo (int) ($cfg['slot_end'] ?? 710); ?>" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 u-gap-12 u-mt-12">
                        <div class="form-group">
                            <label class="form-label" for="pk_parking_time"><?php echo $h(t('parking.field_parking_time')); ?></label>
                            <input type="number" id="pk_parking_time" name="parking_time" class="form-control" min="10" max="600" value="<?php echo (int) ($cfg['parking_time'] ?? 45); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pk_findslot"><?php echo $h(t('parking.field_findslot')); ?></label>
                            <select id="pk_findslot" name="findslot" class="form-control">
                                <option value="next" <?php echo (($cfg['findslot'] ?? 'next') === 'next') ? 'selected' : ''; ?>><?php echo $h(t('parking.findslot_next')); ?></option>
                                <option value="first" <?php echo (($cfg['findslot'] ?? '') === 'first') ? 'selected' : ''; ?>><?php echo $h(t('parking.findslot_first')); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pk_parked_play"><?php echo $h(t('parking.field_parked_play')); ?></label>
                            <select id="pk_parked_play" name="parked_play" class="form-control">
                                <?php foreach (['caller', 'parked', 'both', 'no'] as $playOpt): ?>
                                    <option value="<?php echo $h($playOpt); ?>" <?php echo (($cfg['parked_play'] ?? 'caller') === $playOpt) ? 'selected' : ''; ?>><?php echo $h(t('parking.play_' . $playOpt)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 u-gap-12 u-mt-12">
                        <div class="form-group">
                            <label class="form-label" for="pk_moh_class"><?php echo $h(t('parking.field_moh')); ?></label>
                            <select id="pk_moh_class" name="moh_class" class="form-control">
                                <?php foreach (($moh_classes ?? ['default']) as $moh): ?>
                                    <option value="<?php echo $h($moh); ?>" <?php echo (($cfg['moh_class'] ?? 'default') === $moh) ? 'selected' : ''; ?>><?php echo $h($moh); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pk_comeback_dial_time"><?php echo $h(t('parking.field_comeback_dial_time')); ?></label>
                            <input type="number" id="pk_comeback_dial_time" name="comeback_dial_time" class="form-control" min="5" max="120" value="<?php echo (int) ($cfg['comeback_dial_time'] ?? 30); ?>" required>
                        </div>
                    </div>

                    <div class="form-group u-mt-12">
                        <label class="form-label u-check-label">
                            <input type="checkbox" name="comeback_to_origin" value="1" <?php echo !empty($cfg['comeback_to_origin']) ? 'checked' : ''; ?> class="u-check">
                            <span><?php echo $h(t('parking.field_comeback_to_origin')); ?></span>
                        </label>
                        <small class="u-hint"><?php echo $h(t('parking.field_comeback_help')); ?></small>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 u-gap-12 u-mt-12">
                        <div class="form-group">
                            <label class="form-label" for="pk_timeout_dest_type"><?php echo $h(t('parking.field_timeout_dest_type')); ?></label>
                            <select id="pk_timeout_dest_type" name="timeout_dest_type" class="form-control" data-dest-target="pk_timeout_dest_id" data-dest-selected="<?php echo $h($timeoutDestId); ?>">
                                <option value=""><?php echo $h(t('parking.no_timeout_dest')); ?></option>
                                <?php foreach (($dest_modules ?? []) as $mod): ?>
                                    <option value="<?php echo $h($mod->getKey()); ?>" <?php echo ($timeoutDestType === $mod->getKey()) ? 'selected' : ''; ?>><?php echo $h($mod->getName()); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pk_timeout_dest_id"><?php echo $h(t('parking.field_timeout_dest_id')); ?></label>
                            <select id="pk_timeout_dest_id" name="timeout_dest_id" class="form-control"></select>
                        </div>
                    </div>

                    <div class="u-d-flex u-jc-flex-end u-mt-16">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $h(t('common.save')); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-phone-volume u-primary"></i> <?php echo $h(t('parking.live_title')); ?></h2>
            </div>
            <div class="card-body">
                <?php if (!$isActive): ?>
                    <p class="u-hint"><?php echo $h(t('parking.live_disabled')); ?></p>
                <?php else: ?>
                    <?php echo uiTableOpen([
                        t('parking.col_slot'),
                        t('parking.col_parked_by'),
                    ]); ?>
                        <?php if (empty($parked_calls)): ?>
                            <?php echo uiTableEmptyRow(2, t('parking.live_empty'), 'fa-parking'); ?>
                        <?php else: ?>
                            <?php foreach ($parked_calls as $pc): ?>
                                <tr>
                                    <td><strong><?php echo $h($pc['space']); ?></strong></td>
                                    <td><?php echo $h($pc['from']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php echo uiTableClose(); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/destinations_helper.js'); ?>"></script>
