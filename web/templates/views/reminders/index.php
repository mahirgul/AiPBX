<?php
$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<?php if (!empty($notices['message'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $h($notices['message']); ?></div>
<?php endif; ?>
<?php if (!empty($notices['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $h($notices['error']); ?></div>
<?php endif; ?>

<div class="card">
    <?php echo uiPageHeaderOpen('fa-bell', t('reminders.title'), 'remindersHelpBox'); ?>
        <span class="badge badge-secondary"><?php echo count($reminders ?? []); ?></span>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('remindersHelpBox', t('reminders.title')); ?>
        <p class="u-m-0"><?php echo $h(t('reminders.help_desc')); ?></p>
    <?php echo uiHelpBoxClose(); ?>

    <?php if (!empty($canEdit)): ?>
    <form method="POST" class="u-mb-16" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token ?? ''); ?>">
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo $h(t('reminders.field_extension')); ?></label>
                <?php if (!empty($isAdmin)): ?>
                    <select name="extension" class="form-control" required>
                        <option value=""><?php echo $h(t('builtin_ai_agent.select')); ?></option>
                        <?php foreach (($extensions ?? []) as $ex): ?>
                            <option value="<?php echo $h($ex['extension']); ?>"<?php echo ($ex['extension'] === ($userExt ?? '')) ? ' selected' : ''; ?>>
                                <?php echo $h($ex['extension'] . ' — ' . $ex['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" name="extension" class="form-control" value="<?php echo $h($userExt ?? ''); ?>" readonly required>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo $h(t('reminders.field_kind')); ?></label>
                <select name="kind" class="form-control">
                    <option value="reminder"><?php echo $h(t('reminders.kind_reminder')); ?></option>
                    <option value="wakeup"><?php echo $h(t('reminders.kind_wakeup')); ?></option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo $h(t('reminders.field_time')); ?></label>
                <input type="datetime-local" name="remind_at" class="form-control" value="<?php echo $h(date('Y-m-d\TH:i', time() + 3600)); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo $h(t('reminders.field_recurrence')); ?></label>
                <select name="recurrence" class="form-control">
                    <option value="none"><?php echo $h(t('reminders.rec_none')); ?></option>
                    <option value="daily"><?php echo $h(t('reminders.rec_daily')); ?></option>
                    <option value="weekdays"><?php echo $h(t('reminders.rec_weekdays')); ?></option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo $h(t('reminders.field_message')); ?></label>
            <input type="text" name="message" class="form-control" maxlength="500" placeholder="<?php echo $h(t('reminders.message_placeholder')); ?>">
        </div>
        <button type="submit" name="create_reminder" value="1" class="btn btn-primary btn-sm">
            <i class="fas fa-plus-circle"></i> <?php echo $h(t('reminders.btn_create')); ?>
        </button>
    </form>
    <?php endif; ?>

    <?php echo uiTableOpen([
        t('reminders.field_extension'),
        t('reminders.field_kind'),
        t('reminders.field_message'),
        t('reminders.field_time'),
        t('reminders.field_recurrence'),
        t('common.status'),
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ]); ?>
        <?php if (empty($reminders)): ?>
            <?php echo uiTableEmptyRow(7, t('reminders.empty'), 'fa-bell'); ?>
        <?php else: ?>
            <?php foreach ($reminders as $r): ?>
                <tr>
                    <td><strong><?php echo $h($r['extension']); ?></strong> <?php if (!empty($r['full_name'])): ?><span class="u-muted u-fs-11"><?php echo $h($r['full_name']); ?></span><?php endif; ?></td>
                    <td><span class="badge badge-info"><?php echo $h($r['kind'] === 'wakeup' ? t('reminders.kind_wakeup') : t('reminders.kind_reminder')); ?></span></td>
                    <td><?php echo $h($r['message']); ?></td>
                    <td class="u-mono u-fs-12"><?php echo $h($r['remind_at']); ?></td>
                    <td><?php echo $h(t('reminders.rec_' . ($r['recurrence'] ?: 'none'))); ?></td>
                    <td><span class="badge badge-secondary"><?php echo $h($r['status']); ?></span></td>
                    <td class="text-right">
                        <?php if (!empty($canEdit) && in_array($r['status'], ['pending', 'failed'], true)): ?>
                            <form method="POST" class="u-d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token ?? ''); ?>">
                                <input type="hidden" name="reminder_id" value="<?php echo (int) $r['id']; ?>">
                                <input type="hidden" name="minutes" value="10">
                                <button type="submit" name="snooze_reminder" value="1" class="btn btn-secondary btn-sm" title="+10m"><i class="fas fa-clock"></i></button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($canEdit) && $r['status'] === 'pending'): ?>
                            <form method="POST" class="u-d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token ?? ''); ?>">
                                <input type="hidden" name="reminder_id" value="<?php echo (int) $r['id']; ?>">
                                <button type="submit" name="cancel_reminder" value="1" class="btn btn-danger btn-sm" title="<?php echo $h(t('common.cancel')); ?>"><i class="fas fa-times"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php echo uiTableClose(); ?>
</div>
