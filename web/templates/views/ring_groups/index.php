<?php
/**
 * Ring Groups (Çalma Grupları) View
 */
use PBX\Destinations\DestinationRegistry;
?>

<div class="card mb-3 my-phone-header-card">
    <div style="display: flex; align-items: center; gap: 12px;">
        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(var(--primary-rgb, 2, 132, 199), 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 20px;">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <h2 style="font-size: 18px; font-weight: 700; margin: 0; color: var(--text-main);"><?php echo t('ring_groups.title', 'Çalma Grupları (Ring Groups)'); ?></h2>
            <div style="font-size: 12px; color: var(--text-muted);"><?php echo t('ring_groups.subtitle', 'Dahili ve harici telefon numaralarını aynı anda veya sırayla çaldırarak ilk açana bağlar.'); ?></div>
        </div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="btn-help" onclick="toggleModuleHelp('rgHelpBox')" title="Modül Rehberi">
            <i class="fas fa-question-circle"></i>
        </button>
        <?php if (hasModulePermission('ring_groups', 'edit')): ?>
            <button class="btn btn-primary btn-sm" onclick="openCreateRgModal()">
                <i class="fas fa-plus-circle"></i> <?php echo t('ring_groups.new_group_btn', 'Yeni Çalma Grubu'); ?>
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Collapsible Help Box -->
<div class="module-help-box" id="rgHelpBox">
    <h4><i class="fas fa-info-circle"></i> <?php echo t('ring_groups.help_title', 'Çalma Grubu Nedir ve Nasıl Çalışır?'); ?></h4>
    <p><?php echo t('ring_groups.help_body', 'Bir çağrı geldiğinde veya grup dahili numarası arandığında birden fazla hedefi aynı anda çaldırır.'); ?></p>
    <ul>
        <li><strong><?php echo t('ring_groups.help_mixed', 'Dahili ve Harici Numaralar:'); ?></strong> <?php echo t('ring_groups.help_mixed_desc', 'Listeye santral içi dahilileri (ör: 1001, 1002) ve cep telefonu / harici sabit hatları (ör: 05051234567) serbestçe virgülle ayırarak yazabilirsiniz.'); ?></li>
        <li><strong><?php echo t('ring_groups.help_first_wins', 'İlk Açan Kazanır:'); ?></strong> <?php echo t('ring_groups.help_first_wins_desc', 'Gruptaki hedeflerden hangisi çağrıyı açarsa, arayan doğrudan ona bağlanır ve diğer tüm çalan telefonlar anında susar.'); ?></li>
        <li><strong><?php echo t('ring_groups.help_internal_ext', 'Sanal Dahili Numarası:'); ?></strong> <?php echo t('ring_groups.help_internal_ext_desc', 'Her çalma grubunun kendi dahili erişim numarası olabilir (ör: 7000). IVR, zaman koşulu ve dahililerden doğrudan bu numara aranabilir.'); ?></li>
    </ul>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 20px;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-layer-group" style="color: var(--primary);"></i> <?php echo t('ring_groups.list_title', 'Tanımlı Çalma Grupları'); ?>
        </div>
        <span class="badge badge-secondary"><?php echo count($ring_groups); ?></span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 70px;">Dahili #</th>
                    <th>Grup Adı</th>
                    <th>Çalacak Numaralar (Hedefler)</th>
                    <th>Strateji</th>
                    <th>Süre</th>
                    <th>Kayıt</th>
                    <th>Durum</th>
                    <th class="text-right">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ring_groups)): ?>
                    <?php echo uiTableEmptyRow(8, t('ring_groups.empty', 'Henüz tanımlanmış bir çalma grubu bulunmuyor.'), 'fa-users'); ?>
                <?php else: ?>
                    <?php foreach ($ring_groups as $rg): ?>
                        <?php 
                        $num_array = array_map('trim', explode(',', $rg['numbers_list'] ?? ''));
                        $num_array = array_filter($num_array);
                        ?>
                        <tr>
                            <td><span class="badge badge-info" style="font-size: 13px;"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($rg['group_number']); ?></span></td>
                            <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($rg['name']); ?></td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                    <?php foreach ($num_array as $num): ?>
                                        <?php if (strlen($num) > 6): ?>
                                            <span class="badge badge-warning" title="Harici Numara"><i class="fas fa-globe"></i> <?php echo htmlspecialchars($num); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-primary" title="Dahili Numara"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($num); ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($rg['ring_strategy'] === 'sequential'): ?>
                                    <span class="badge badge-secondary"><i class="fas fa-sort-numeric-down"></i> Sırayla</span>
                                <?php elseif ($rg['ring_strategy'] === 'random'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-random"></i> Rastgele</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fas fa-bell"></i> Hepsi Birlikte</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-secondary"><?php echo (int)$rg['ring_timeout']; ?> sn</span></td>
                            <td>
                                <?php if ((int)$rg['record_call'] === 1): ?>
                                    <span class="badge badge-danger" title="Görüşme Kaydediliyor"><i class="fas fa-microphone"></i> Kayıt</span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 11px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$rg['is_active'] === 1): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times"></i> Pasif</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if (hasModulePermission('ring_groups', 'edit')): ?>
                                    <div style="display: inline-flex; gap: 4px;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditRgModal(<?php echo json_encode($rg); ?>)' title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Bu çalma grubunu silmek istediğinizden emin misiniz?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="delete_ring_group" value="1">
                                            <input type="hidden" name="ring_group_id" value="<?php echo $rg['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Sil">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Çalma Grubu Ekle / Düzenle -->
<div class="modal-overlay" id="rgModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="rgModalTitle"><i class="fas fa-users" style="color: var(--primary);"></i> Çalma Grubu</h3>
            <button class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('rgModal')" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_ring_group" value="1">
                <input type="hidden" name="id" id="modal_rg_id" value="0">

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Grup Dahili Numarası</label>
                        <input type="text" name="group_number" id="modal_rg_number" class="form-control" placeholder="ör: 7000" pattern="[0-9]{3,6}" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Grup Adı</label>
                        <input type="text" name="name" id="modal_rg_name" class="form-control" placeholder="ör: Satış ve Destek Ekibi" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Çalacak Numaralar (Dahili & Harici GSM/Sabit)</label>
                    <textarea name="numbers_list" id="modal_rg_numbers" class="form-control" rows="3" placeholder="ör: 1001, 1002, 05051234567, 02129876543" required></textarea>
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">
                        Numaraları virgülle veya boşlukla ayırarak yazabilirsiniz. Santral dahilileri ve harici numaralar aynı grupta birlikte çalabilir.
                    </small>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Çalma Stratejisi</label>
                        <select name="ring_strategy" id="modal_rg_strategy" class="form-control">
                            <option value="ringall">Hepsi Birlikte (Aynı Anda Çal - İlk Açan Bağlanır)</option>
                            <option value="sequential">Sırayla (Teker Teker Çal)</option>
                            <option value="random">Rastgele (Hedefler Arasından Rastgele Seç)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Çalma Süresi (Zaman Aşımı)</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="ring_timeout" id="modal_rg_timeout" class="form-control" value="30" min="5" max="300" required>
                            <span style="font-size: 12px; color: var(--text-muted);">saniye</span>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Arayan Numaraya Ön Ek (CID Prefix)</label>
                        <input type="text" name="cid_prefix" id="modal_rg_cid_prefix" class="form-control" placeholder="ör: [SATIŞ] ">
                    </div>

                    <div class="form-group" style="display: flex; align-items: flex-end; padding-bottom: 8px;">
                        <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; margin: 0;">
                            <input type="checkbox" name="record_call" id="modal_rg_record" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span style="font-weight: 600;">Görüşmeyi Ses Kaydı Yap</span>
                        </label>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Kimse Açmazsa Hedef Tipi</label>
                        <select name="fallback_dest_type" id="modal_rg_dest_type" class="form-control" onchange="loadRgDestOptions()">
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Hedef</label>
                        <select name="fallback_dest_id" id="modal_rg_dest_id" class="form-control">
                            <option value="">Yükleniyor...</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_rg_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;">Grup Aktif</span>
                    </label>
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('rgModal')", t('common.save', 'Kaydet'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script>
let currentRgDestId = '';

function loadRgDestOptions(callback) {
    const type = document.getElementById('modal_rg_dest_type').value;
    const destSelect = document.getElementById('modal_rg_dest_id');
    destSelect.innerHTML = '<option value="">Yükleniyor...</option>';

    fetch('/api/destinations.php?module=' + encodeURIComponent(type))
        .then(r => r.json())
        .then(data => {
            destSelect.innerHTML = '';
            if (data.success && data.options && data.options.length > 0) {
                data.options.forEach(opt => {
                    const el = document.createElement('option');
                    el.value = opt.id;
                    el.textContent = opt.name;
                    if (String(opt.id) === String(currentRgDestId)) el.selected = true;
                    destSelect.appendChild(el);
                });
            } else {
                destSelect.innerHTML = '<option value="">(Hedef bulunamadı)</option>';
            }
            if (callback) callback();
        })
        .catch(() => {
            destSelect.innerHTML = '<option value="">(Hata)</option>';
        });
}

function openCreateRgModal() {
    document.getElementById('rgModalTitle').innerHTML = '<i class="fas fa-plus-circle" style="color: var(--primary);"></i> Yeni Çalma Grubu';
    document.getElementById('modal_rg_id').value = '0';
    document.getElementById('modal_rg_number').value = '';
    document.getElementById('modal_rg_name').value = '';
    document.getElementById('modal_rg_numbers').value = '';
    document.getElementById('modal_rg_strategy').value = 'ringall';
    document.getElementById('modal_rg_timeout').value = '30';
    document.getElementById('modal_rg_cid_prefix').value = '';
    document.getElementById('modal_rg_record').checked = true;
    document.getElementById('modal_rg_active').checked = true;

    document.getElementById('modal_rg_dest_type').value = 'hangup';
    currentRgDestId = 'busy';
    loadRgDestOptions();
    UIHelper.openOverlayModal('rgModal');
}

function openEditRgModal(rg) {
    document.getElementById('rgModalTitle').innerHTML = '<i class="fas fa-edit" style="color: var(--primary);"></i> Çalma Grubu Düzenle: ' + escapeHtml(rg.name || '');
    document.getElementById('modal_rg_id').value = rg.id || '0';
    document.getElementById('modal_rg_number').value = rg.group_number || '';
    document.getElementById('modal_rg_name').value = rg.name || '';
    document.getElementById('modal_rg_numbers').value = rg.numbers_list || '';
    document.getElementById('modal_rg_strategy').value = rg.ring_strategy || 'ringall';
    document.getElementById('modal_rg_timeout').value = rg.ring_timeout || 30;
    document.getElementById('modal_rg_cid_prefix').value = rg.cid_prefix || '';
    document.getElementById('modal_rg_record').checked = (rg.record_call == 1);
    document.getElementById('modal_rg_active').checked = (rg.is_active == 1);

    document.getElementById('modal_rg_dest_type').value = rg.fallback_dest_type || 'hangup';
    currentRgDestId = rg.fallback_dest_id || 'busy';
    loadRgDestOptions();
    UIHelper.openOverlayModal('rgModal');
}
</script>
