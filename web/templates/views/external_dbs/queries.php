<?php
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$currentId = (int) ($query_edit['id'] ?? 0);
?>
<div class="card">
    <?php echo uiTableOpen([
        t('external_dbs.query_key'), t('external_dbs.query_description'), t('external_dbs.name'),
        t('external_dbs.query_access'), t('common.status'), '',
    ], ['frame' => 'u-card-pad']); ?>
    <?php if (!$queries): ?><?php echo uiTableEmptyRow(6, t('common.no_records')); ?><?php endif; ?>
    <?php foreach ($queries as $query): ?>
        <tr>
            <td><code><?php echo $h($query['query_key']); ?></code></td>
            <td><?php echo $h($query['description']); ?></td>
            <td><?php echo $h($query['connection_name']); ?></td>
            <td><?php echo $h($query['access_mode'] === 'verified' ? t('external_dbs.query_verified') : t('external_dbs.query_public')); ?></td>
            <td><?php echo $h(ExternalDbQueryService::available($query) ? t('common.active') : t('common.passive')); ?></td>
            <td class="u-ta-right">
                <a class="btn btn-icon btn-secondary btn-sm" href="/external-dbs?tab=queries&amp;edit_query=<?php echo (int) $query['id']; ?>"
                   aria-label="<?php echo $h(t('common.edit')); ?>"><i class="fas fa-pen"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>

<?php if ($query_edit !== null): ?>
<div class="card u-card-pad">
    <form method="post" action="/external-dbs?tab=queries">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="action" value="save_query">
        <input type="hidden" name="id" value="<?php echo $currentId; ?>">
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label" for="saved_query_key"><?php echo $h(t('external_dbs.query_key')); ?></label>
                <input id="saved_query_key" class="form-control u-mono" name="query_key" maxlength="30" pattern="[a-z0-9_]{1,30}"
                       required value="<?php echo $h($query_edit['query_key'] ?? ''); ?>"<?php echo $currentId ? ' readonly' : ''; ?>>
            </div>
            <div class="form-group">
                <label class="form-label" for="saved_query_db"><?php echo $h(t('external_dbs.name')); ?></label>
                <select id="saved_query_db" name="external_db_id" class="form-control" required>
                    <option value=""><?php echo $h(t('dynamic_routing.db_pick')); ?></option>
                    <?php foreach ($connections as $connection): ?>
                        <?php if (!empty($connection['allow_write'])) { continue; } ?>
                        <option value="<?php echo (int) $connection['id']; ?>"<?php echo (int) $connection['id'] === (int) ($query_edit['external_db_id'] ?? 0) ? ' selected' : ''; ?>>
                            <?php echo $h($connection['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="saved_query_description"><?php echo $h(t('external_dbs.query_description')); ?></label>
            <input id="saved_query_description" class="form-control" name="description" maxlength="300" required value="<?php echo $h($query_edit['description'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="saved_query_access"><?php echo $h(t('external_dbs.query_access')); ?></label>
            <select id="saved_query_access" name="access_mode" class="form-control">
                <option value="verified"><?php echo $h(t('external_dbs.query_verified')); ?></option>
                <option value="public"<?php echo ($query_edit['access_mode'] ?? '') === 'public' ? ' selected' : ''; ?>><?php echo $h(t('external_dbs.query_public')); ?></option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="saved_query_sql"><?php echo $h(t('dynamic_routing.sql')); ?></label>
            <textarea id="saved_query_sql" class="form-control u-mono" name="query_sql" maxlength="8000" rows="6" required aria-describedby="savedQueryHelp"><?php echo $h($query_edit['query_sql'] ?? ''); ?></textarea>
            <small id="savedQueryHelp" class="u-hint"><?php echo $h(t('external_dbs.query_help')); ?></small>
        </div>
        <div class="form-group">
            <label class="u-check-label">
                <input type="checkbox" class="u-accent" name="is_active" value="1"<?php echo $query_edit === [] || !empty($query_edit['is_active']) ? ' checked' : ''; ?>>
                <?php echo $h(t('common.active')); ?>
            </label>
        </div>
        <div class="u-flex-gap">
            <?php echo uiSaveButton(); ?>
            <a class="btn btn-secondary" href="/external-dbs?tab=queries"><?php echo $h(t('common.cancel')); ?></a>
        </div>
    </form>
</div>
<?php endif; ?>
