<?php
/**
 * Key layout of one user's desk phone: the phone's keys and its expansion
 * module pages, drawn by the model's key count (PhoneModels).
 *
 * @var array $users
 * @var array|null $user
 * @var string $model
 * @var array $keys
 */
$canEdit = hasModulePermission('phones', 'edit');
$csrf = getCSRFToken();
$pages = [];
for ($page = 0; $page < PhoneModels::pageCount($model); $page++) {
    $pages[] = [
        'page' => $page,
        'count' => PhoneModels::keysOnPage($model, $page),
        'title' => $page === 0 ? t('phones.keys_page_phone') : sprintf(t('phones.keys_page_module'), $page),
    ];
}
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/phones.css'); ?>">

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-th u-primary"></i> <?php echo t('phones.keys_title'); ?>
            <?php if ($user): ?>
                <span class="badge badge-info u-ml-8"><?php echo htmlspecialchars($user['extension'] . ' — ' . $user['full_name']); ?></span>
            <?php endif; ?>
        </div>
        <div class="u-flex-center">
            <a href="/phones" class="btn btn-secondary btn-sm" title="<?php echo t('phones.title'); ?>"><i class="fas fa-phone"></i></a>
        </div>
    </div>

    <form method="GET" class="phone-keys-toolbar">
        <div class="form-group">
            <label class="form-label"><?php echo t('phones.col_extension'); ?></label>
            <select name="user" class="form-control" onchange="this.form.submit()">
                <option value="0">—</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int) $u['id']; ?>" <?php echo $user && (int) $user['id'] === (int) $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['extension'] . ' — ' . $u['full_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('phones.col_model'); ?></label>
            <select name="model" class="form-control" onchange="this.form.submit()">
                <?php foreach (PhoneModels::MODELS as $key => $m): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === $model ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <p class="text-muted u-fs-12"><?php echo t('phones.keys_desc'); ?></p>
    <?php if (PhoneModels::vendorOf($model) === 'grandstream'): ?>
        <p class="text-muted u-fs-12"><i class="fas fa-info-circle"></i> <?php echo sprintf(t('phones.keys_gs_limit'), GrandstreamTemplate::WRITTEN_KEYS); ?></p>
    <?php endif; ?>

    <?php if (!$user): ?>
        <div class="text-center text-muted u-p-30"><i class="fas fa-hand-pointer fa-2x"></i><br><br><?php echo t('phones.keys_pick_user'); ?></div>
    <?php else: ?>
        <form method="POST" id="phoneKeysForm" onsubmit="return collectPhoneKeys();">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
            <input type="hidden" name="keys_json" id="phone_keys_json" value="[]">
            <div id="phoneKeysPages"></div>
            <?php if ($canEdit): ?>
                <button type="submit" name="save_keys" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<?php if ($user && $canEdit): ?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-copy u-primary"></i> <?php echo t('phones.keys_copy_title'); ?></div>
    </div>
    <form method="POST" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('phones.keys_copy_confirm')), ENT_QUOTES); ?>);">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
        <p class="text-muted u-fs-12"><?php echo t('phones.keys_copy_desc'); ?></p>
        <div class="form-group">
            <select name="to_users[]" class="form-control" multiple size="8">
                <?php foreach ($users as $u): ?>
                    <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                        <option value="<?php echo (int) $u['id']; ?>"><?php echo htmlspecialchars($u['extension'] . ' — ' . $u['full_name']); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" name="copy_keys" value="1" class="btn btn-secondary"><i class="fas fa-copy"></i> <?php echo t('phones.keys_copy_btn'); ?></button>
    </form>
</div>
<?php endif; ?>

<script>
window.PHONE_KEYS = <?php echo json_encode([
    'pages' => $pages,
    'keys' => $keys,
    'types' => PhoneProvisionService::KEY_TYPES,
    'editable' => $canEdit,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="<?php echo asset('/assets/js/phone_keys.js'); ?>"></script>
