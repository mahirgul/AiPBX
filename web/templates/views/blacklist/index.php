<?php
$can_edit = $canEdit ?? false;
$can_delete = $canDelete ?? false;
?>

<?php if (!empty($notices['message'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($notices['message']); ?></div>
<?php endif; ?>
<?php if (!empty($notices['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($notices['error']); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-ban text-danger"></i> <?php echo t('blacklist.title'); ?>
        </div>
        <div class="u-flex-gap">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('blacklistHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openImportModal()" title="<?php echo t('blacklist.import_csv'); ?>">
                    <i class="fas fa-file-csv"></i> <?php echo t('blacklist.import_csv'); ?>
                </button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(t('blacklist.confirm_resync', 'Tüm kara liste kuralları AstDB ve arama planına yeniden yazılsın mı?')); ?>');">
                    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                    <input type="hidden" name="action" value="resync">
                    <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo t('blacklist.resync_astdb'); ?>">
                        <i class="fas fa-sync-alt"></i> <?php echo t('blacklist.resync_btn', 'Eşitle'); ?>
                    </button>
                </form>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreateBlacklistModal()" title="<?php echo t('common.add_new'); ?>">
                    <i class="fas fa-plus-circle"></i> <?php echo t('blacklist.new_entry'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="blacklistHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('blacklist.help_title', 'Kara Liste & Spam Engelleme Rehberi'); ?></h4>
        <p><?php echo t('blacklist.help_desc', 'İstenmeyen veya spam arayan numaraları dahili hatlara, kuyruklara veya karşılama anonslarına (IVR) ulaşmadan önce en erken noktada (Trunk Inbound) engeller. Arayan numara doğrudan Asterisk Dahili Veritabanı (AstDB) üzerinde mikro-saniyeler içinde kontrol edilir.'); ?></p>
        <ul style="margin: 8px 0 0 20px; font-size: 13px;">
            <li><strong><?php echo t('blacklist.action_busy'); ?>:</strong> <?php echo t('blacklist.help_act_busy', 'Arayana meşgul sinyali (ISDN 17 / SIP 486 Busy Here) döner.'); ?></li>
            <li><strong><?php echo t('blacklist.action_congestion'); ?>:</strong> <?php echo t('blacklist.help_act_congestion', 'Şebeke arızası / devre yok (ISDN 34 / SIP 503 Service Unavailable) döner.'); ?></li>
            <li><strong><?php echo t('blacklist.action_drop'); ?>:</strong> <?php echo t('blacklist.help_act_drop', 'Görüşmeyi hiçbir ses veya anons dinletmeden doğrudan sonlandırır (ISDN 16).'); ?></li>
            <li><strong><?php echo t('blacklist.action_announcement'); ?>:</strong> <?php echo t('blacklist.help_act_announcement', 'Çağrıyı yanıtlar ve "Numara kullanılmamaktadır" sistem anonsunu dinleterek kapatır.'); ?></li>
            <li><strong><?php echo t('blacklist.help_patterns', 'Desen Desteği'); ?>:</strong> <?php echo t('blacklist.help_patterns_desc', 'Belirli bir numara öbeğini engellemek için dialplan kalıpları kullanabilirsiniz (Örn: _0850. veya _+1800.).'); ?></li>
        </ul>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="blacklistTable">
            <thead>
                <tr>
                    <th class="col-hide-mobile u-w-50">#</th>
                    <th><?php echo t('blacklist.col_number'); ?></th>
                    <th><?php echo t('blacklist.col_title'); ?></th>
                    <th><?php echo t('blacklist.col_action'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('blacklist.col_hits'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('blacklist.col_last_blocked'); ?></th>
                    <th><?php echo t('common.status'); ?></th>
                    <th class="text-right"><?php echo t('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                    <?php echo uiTableEmptyRow(8, t('blacklist.empty', 'Kayıtlı engellenmiş numara bulunmamaktadır.'), 'fa-shield-alt'); ?>
                <?php else: ?>
                    <?php foreach ($entries as $e): ?>
                        <tr>
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo (int)$e['id']; ?></td>
                            <td>
                                <span class="badge badge-danger">
                                    <i class="fas fa-phone-slash"></i> <code><?php echo htmlspecialchars($e['number']); ?></code>
                                </span>
                                <?php if (str_starts_with((string)$e['number'], '_')): ?>
                                    <span class="badge badge-secondary u-fs-10" title="Dialplan Pattern">Pattern</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo !empty($e['title']) ? htmlspecialchars($e['title']) : '<span class="text-muted">-</span>'; ?>
                            </td>
                            <td>
                                <?php
                                $act = (string)($e['block_action'] ?? 'busy');
                                switch ($act) {
                                    case 'congestion':
                                        echo '<span class="badge badge-warning"><i class="fas fa-exclamation-circle"></i> ' . t('blacklist.action_congestion') . '</span>';
                                        break;
                                    case 'drop':
                                        echo '<span class="badge badge-dark"><i class="fas fa-phone-slash"></i> ' . t('blacklist.action_drop') . '</span>';
                                        break;
                                    case 'announcement':
                                        echo '<span class="badge badge-info"><i class="fas fa-volume-up"></i> ' . t('blacklist.action_announcement') . '</span>';
                                        break;
                                    case 'busy':
                                    default:
                                        echo '<span class="badge badge-secondary"><i class="fas fa-phone-volume"></i> ' . t('blacklist.action_busy') . '</span>';
                                        break;
                                }
                                ?>
                            </td>
                            <td class="col-hide-mobile">
                                <span class="badge <?php echo (int)$e['hit_count'] > 0 ? 'badge-primary' : 'badge-light'; ?>">
                                    <?php echo (int)$e['hit_count']; ?>
                                </span>
                            </td>
                            <td class="col-hide-mobile u-fs-12 text-muted">
                                <?php echo !empty($e['last_blocked_at']) ? htmlspecialchars($e['last_blocked_at']) : '-'; ?>
                            </td>
                            <td>
                                <?php if ($can_edit): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="entry_id" value="<?php echo (int)$e['id']; ?>">
                                        <button type="submit" class="badge <?php echo !empty($e['is_active']) ? 'badge-success' : 'badge-secondary'; ?>" style="border:none; cursor:pointer;" title="<?php echo t('common.toggle_status'); ?>">
                                            <?php echo !empty($e['is_active']) ? t('common.active') : t('common.passive'); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge <?php echo !empty($e['is_active']) ? 'badge-success' : 'badge-secondary'; ?>">
                                        <?php echo !empty($e['is_active']) ? t('common.active') : t('common.passive'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if ($can_edit): ?>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick='openEditBlacklistModal(<?php echo json_encode($e, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="<?php echo t('common.edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(sprintf(t('blacklist.confirm_delete', '%s numarası kara listeden silinsin mi?'), $e['number'])); ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="delete_entry">
                                        <input type="hidden" name="entry_id" value="<?php echo (int)$e['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Create / Edit Blacklist Entry -->
<div class="modal-overlay" id="blacklistModal">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="u-title" id="blacklistModalTitle"><i class="fas fa-ban text-danger"></i> <?php echo t('blacklist.new_entry'); ?></h3>
            <button type="button" class="btn btn-secondary u-btn-pad" onclick="closeBlacklistModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off" id="blacklistForm">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="save_entry">
                <input type="hidden" name="entry_id" id="modal_entry_id" value="">

                <!-- Quick caller picker from extension -->
                <div class="form-group" style="background: var(--bg-hover, #f8fafc); padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                    <label class="form-label u-fs-12"><i class="fas fa-bolt text-warning"></i> <?php echo t('blacklist.quick_lookup_title', 'Dahili Son Çağrısından Numarayı Al'); ?></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" id="quick_ext_input" class="form-control u-fs-12" placeholder="<?php echo t('blacklist.quick_ext_ph', 'Dahili no (örn: 101)'); ?>">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="fetchLastCallerForExt()">
                            <i class="fas fa-search"></i> <?php echo t('common.search'); ?>
                        </button>
                    </div>
                    <small id="quick_ext_hint" class="u-hint u-fs-11" style="display: none;"></small>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label"><?php echo t('blacklist.col_number'); ?> <span class="text-danger">*</span></label>
                    <input type="text" name="number" id="modal_number" class="form-control" placeholder="<?php echo t('blacklist.number_ph', 'Örn: 08501234567 veya _0850.'); ?>" required>
                    <small class="u-hint u-fs-11"><?php echo t('blacklist.number_hint', 'Tek bir numara (+90555..., 0850...) veya desen (_0850., _+1800.) girebilirsiniz.'); ?></small>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label"><?php echo t('blacklist.col_title'); ?></label>
                    <input type="text" name="title" id="modal_title" class="form-control" placeholder="<?php echo t('blacklist.title_ph', 'Örn: Dolandırıcı / Spam Çağrı Merkezi'); ?>">
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label"><?php echo t('blacklist.col_action'); ?></label>
                    <select name="block_action" id="modal_block_action" class="form-control">
                        <option value="busy"><?php echo t('blacklist.action_busy'); ?></option>
                        <option value="congestion"><?php echo t('blacklist.action_congestion'); ?></option>
                        <option value="drop"><?php echo t('blacklist.action_drop'); ?></option>
                        <option value="announcement"><?php echo t('blacklist.action_announcement'); ?></option>
                    </select>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked class="u-check">
                        <span class="u-fw-600"><?php echo t('common.active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeBlacklistModal()'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Modal: CSV Import -->
<div class="modal-overlay" id="importModal">
    <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-file-csv text-primary"></i> <?php echo t('blacklist.import_csv'); ?></h3>
            <button type="button" class="btn btn-secondary u-btn-pad" onclick="closeImportModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="import_csv">

                <div class="form-group">
                    <label class="form-label"><?php echo t('blacklist.csv_file_label', 'CSV Dosyası'); ?> <span class="text-danger">*</span></label>
                    <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" class="form-control" required>
                    <small class="u-hint u-fs-11"><?php echo t('blacklist.csv_format_hint', 'Format: numara,açıklama,aksiyon (busy/congestion/drop/announcement)'); ?></small>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label"><?php echo t('blacklist.default_action_label', 'Aksiyon Belirtilmemişse Varsayılan'); ?></label>
                    <select name="default_action" class="form-control">
                        <option value="busy"><?php echo t('blacklist.action_busy'); ?></option>
                        <option value="congestion"><?php echo t('blacklist.action_congestion'); ?></option>
                        <option value="drop"><?php echo t('blacklist.action_drop'); ?></option>
                        <option value="announcement"><?php echo t('blacklist.action_announcement'); ?></option>
                    </select>
                </div>

                <?php echo uiModalFooter('closeImportModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script>
function openCreateBlacklistModal() {
    document.getElementById('blacklistModalTitle').innerHTML = '<i class="fas fa-ban text-danger"></i> ' + <?php echo json_encode(t('blacklist.new_entry')); ?>;
    document.getElementById('modal_entry_id').value = '';
    document.getElementById('modal_number').value = '';
    document.getElementById('modal_title').value = '';
    document.getElementById('modal_block_action').value = 'busy';
    document.getElementById('modal_is_active').checked = true;
    document.getElementById('quick_ext_input').value = '';
    document.getElementById('quick_ext_hint').style.display = 'none';

    UIHelper.openOverlayModal('blacklistModal');
    setTimeout(() => document.getElementById('modal_number').focus(), 100);
}

function openEditBlacklistModal(item) {
    if (!item) return;
    document.getElementById('blacklistModalTitle').innerHTML = '<i class="fas fa-edit text-primary"></i> ' + <?php echo json_encode(t('blacklist.edit_entry')); ?> + ' - ' + escapeHtml(item.number || '');
    document.getElementById('modal_entry_id').value = item.id || '';
    document.getElementById('modal_number').value = item.number || '';
    document.getElementById('modal_title').value = item.title || '';
    document.getElementById('modal_block_action').value = item.block_action || 'busy';
    document.getElementById('modal_is_active').checked = (parseInt(item.is_active) === 1);
    document.getElementById('quick_ext_input').value = '';
    document.getElementById('quick_ext_hint').style.display = 'none';

    UIHelper.openOverlayModal('blacklistModal');
}

function closeBlacklistModal() {
    UIHelper.closeOverlayModal('blacklistModal');
}

function openImportModal() {
    UIHelper.openOverlayModal('importModal');
}

function closeImportModal() {
    UIHelper.closeOverlayModal('importModal');
}

function fetchLastCallerForExt() {
    const ext = document.getElementById('quick_ext_input').value.trim();
    const hint = document.getElementById('quick_ext_hint');
    if (!ext) {
        hint.textContent = <?php echo json_encode(t('blacklist.err_enter_ext', 'Lütfen dahili numara girin.')); ?>;
        hint.style.color = 'var(--danger)';
        hint.style.display = 'block';
        return;
    }

    fetch('/blacklist?action=last_caller&extension=' + encodeURIComponent(ext))
        .then(res => res.json())
        .then(data => {
            if (data.success && data.caller) {
                document.getElementById('modal_number').value = data.caller;
                hint.textContent = <?php echo json_encode(t('blacklist.caller_found', 'Son arayan numara aktarıldı: ')); ?> + data.caller;
                hint.style.color = 'var(--success)';
                hint.style.display = 'block';
            } else {
                hint.textContent = <?php echo json_encode(t('blacklist.no_caller_found', 'Bu dahili için son gelen çağrı bulunamadı.')); ?>;
                hint.style.color = 'var(--warning)';
                hint.style.display = 'block';
            }
        })
        .catch(err => {
            hint.textContent = <?php echo json_encode(t('common.action_failed')); ?>;
            hint.style.color = 'var(--danger)';
            hint.style.display = 'block';
        });
}
</script>
