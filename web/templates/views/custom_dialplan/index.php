<?php
$can_edit = $canEdit ?? false;
$can_delete = $canDelete ?? false;
?>

<?php if (!empty($notices['message'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($notices['message']); ?></div>
<?php endif; ?>
<?php if (!empty($notices['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $notices['error']; ?></div>
<?php endif; ?>
<?php if (!empty($notices['warnings'])): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-circle"></i> <strong><?php echo t('custom_dialplan.warnings_title', 'Sözdizimi Uyarıları'); ?>:</strong>
        <ul style="margin: 4px 0 0 20px;">
            <?php foreach ($notices['warnings'] as $w): ?>
                <li><?php echo htmlspecialchars($w); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-code text-primary"></i> <?php echo t('custom_dialplan.title', 'Özel Arama Planı (Custom Dialplan)'); ?>
        </div>
        <div class="u-flex-gap">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('customDialplanHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openPreviewModal()" title="<?php echo t('custom_dialplan.preview_conf', 'Arama Planı Önizleme'); ?>">
                <i class="fas fa-eye"></i> <?php echo t('custom_dialplan.btn_preview', 'Önizle'); ?>
            </button>
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreateCustomDialplanModal()" title="<?php echo t('common.add_new'); ?>">
                    <i class="fas fa-plus-circle"></i> <?php echo t('custom_dialplan.new_entry', 'Yeni Özel Context'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="customDialplanHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('custom_dialplan.help_title', 'Özel Arama Planı (Custom Dialplan) Rehberi'); ?></h4>
        <p><?php echo t('custom_dialplan.help_desc', 'Bu sayfa üzerinden Asterisk santralinize doğrudan özel dialplan blokları, context\'ler ve kod kancaları ekleyebilirsiniz. Tanımladığınız girişler /etc/asterisk/pbx/extensions_custom.conf dosyasına otomatik yazılır ve santral yeniden yüklenir.'); ?></p>
        <ul style="margin: 8px 0 0 20px; font-size: 13px;">
            <li><strong><?php echo t('custom_dialplan.help_internal_title', 'Dahili Aramalara Dahil Et'); ?>:</strong> <?php echo t('custom_dialplan.help_internal_desc', 'İşaretlendiğinde, bu context otomatik olarak [from-internal-pbx-ortak] içine dahil edilir. Böylece tüm dahili telefonlar bu context içindeki özel kodları (*80, *55 vb.) tuşlayabilir.'); ?></li>
            <li><strong><?php echo t('custom_dialplan.help_standalone_title', 'Bağımsız Context'); ?>:</strong> <?php echo t('custom_dialplan.help_standalone_desc', 'Dahili plana dahil edilmeyen context\'ler dış hat (trunk) gelen çağrı filtreleri, IVR kancaları veya özel AGI senaryoları için kullanılır.'); ?></li>
            <li><strong><?php echo t('custom_dialplan.help_syntax_title', 'Sözdizimi'); ?>:</strong> <?php echo t('custom_dialplan.help_syntax_desc', 'Standart Asterisk dialplan formatı: exten => ..., same => ..., include => ... kuralları geçerlidir.'); ?></li>
        </ul>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="customDialplanTable">
            <thead>
                <tr>
                    <th class="col-hide-mobile u-w-50"><?php echo t('custom_dialplan.col_order', 'Sıra'); ?></th>
                    <th><?php echo t('custom_dialplan.col_title', 'Başlık'); ?></th>
                    <th><?php echo t('custom_dialplan.col_context', 'Context Adı'); ?></th>
                    <th><?php echo t('custom_dialplan.col_internal', 'Dahili Plan'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('custom_dialplan.col_desc', 'Açıklama'); ?></th>
                    <th><?php echo t('common.status'); ?></th>
                    <th class="text-right"><?php echo t('common.actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                    <?php echo uiTableEmptyRow(7, t('custom_dialplan.empty', 'Kayıtlı özel arama planı context\'i bulunmamaktadır.'), 'fa-code'); ?>
                <?php else: ?>
                    <?php foreach ($entries as $e): ?>
                        <tr>
                            <td class="col-hide-mobile text-muted u-fs-12"><?php echo (int)($e['sort_order'] ?? 10); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars((string)$e['title']); ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-secondary">
                                    <i class="fas fa-terminal"></i> [<?php echo htmlspecialchars((string)$e['context_name']); ?>]
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($e['include_in_internal'])): ?>
                                    <span class="badge badge-success" title="<?php echo t('custom_dialplan.internal_included_tooltip', 'Dahili aramalardan (* vb.) doğrudan erişilebilir'); ?>">
                                        <i class="fas fa-check"></i> <?php echo t('custom_dialplan.badge_internal', 'Dahil'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-light text-muted" title="<?php echo t('custom_dialplan.standalone_tooltip', 'Yalnızca doğrudan yönlendirilen çağrılar erişebilir'); ?>">
                                        <i class="fas fa-lock"></i> <?php echo t('custom_dialplan.badge_standalone', 'Bağımsız'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile text-muted u-fs-12">
                                <?php echo !empty($e['description']) ? htmlspecialchars((string)$e['description']) : '-'; ?>
                            </td>
                            <td>
                                <?php if ($can_edit): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="entry_id" value="<?php echo (int)$e['id']; ?>">
                                        <button type="submit" class="badge-btn <?php echo !empty($e['is_active']) ? 'badge-success' : 'badge-secondary'; ?>">
                                            <?php echo !empty($e['is_active']) ? t('common.active') : t('common.inactive'); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge <?php echo !empty($e['is_active']) ? 'badge-success' : 'badge-secondary'; ?>">
                                        <?php echo !empty($e['is_active']) ? t('common.active') : t('common.inactive'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if ($can_edit): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="openEditCustomDialplanModal(<?php echo (int)$e['id']; ?>)" title="<?php echo t('common.edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm(<?php echo jsArg(t('custom_dialplan.confirm_delete')); ?>);">
                                        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="delete_entry">
                                        <input type="hidden" name="entry_id" value="<?php echo (int)$e['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="<?php echo t('common.delete'); ?>">
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

<!-- Modal: Create / Edit Custom Dialplan -->
<div class="modal fade" id="customDialplanModal" tabindex="-1" role="dialog" aria-hidden="true" style="display:none;">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="customDialplanForm">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="save_entry">
                <input type="hidden" name="entry_id" id="cdEntryId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="customDialplanModalTitle">
                        <i class="fas fa-code text-primary"></i> <?php echo t('custom_dialplan.modal_new_title', 'Yeni Özel Arama Planı Context\'i'); ?>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" onclick="closeCustomDialplanModal()">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <!-- Quick Templates selector -->
                    <div class="form-group mb-3" style="background: var(--bg-secondary, #f8f9fa); padding: 12px; border-radius: 6px; border: 1px dashed var(--border-color, #dee2e6);">
                        <label for="templateSelect" class="font-weight-bold">
                            <i class="fas fa-magic text-warning"></i> <?php echo t('custom_dialplan.quick_templates', 'Hızlı Şablonlar / Hazır PBX Özellikleri'); ?>:
                        </label>
                        <div class="input-group">
                            <select id="templateSelect" class="form-control" onchange="applyTemplate(this.value)">
                                <option value=""><?php echo t('custom_dialplan.select_template', '-- Hazır bir şablon seçerek formu doldurun --'); ?></option>
                                <?php foreach ($templates as $tKey => $tpl): ?>
                                    <option value="<?php echo htmlspecialchars($tKey); ?>"><?php echo htmlspecialchars($tpl['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <small class="form-text text-muted">
                            <?php echo t('custom_dialplan.quick_template_hint', 'Dahili İnterkom (*80), Dinleme & Fısıldama (*55/*56), Anons Grubu (*700), CRM Webhook veya VIP Yönlendirme şablonunu anında yükleyebilirsiniz.'); ?>
                        </small>
                    </div>

                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group mb-3">
                                <label for="cdTitle" class="font-weight-bold"><?php echo t('custom_dialplan.col_title', 'Başlık'); ?> <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="title" id="cdTitle" required maxlength="100" placeholder="<?php echo t('custom_dialplan.title_placeholder', 'Örn: Dahili İnterkom ve Anons'); ?>">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group mb-3">
                                <label for="cdContextName" class="font-weight-bold"><?php echo t('custom_dialplan.col_context', 'Context Adı'); ?> <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text">[</span></div>
                                    <input type="text" class="form-control font-monospace" name="context_name" id="cdContextName" required maxlength="80" pattern="[a-zA-Z0-9_\-]+" placeholder="custom-feature">
                                    <div class="input-group-append"><span class="input-group-text">]</span></div>
                                </div>
                                <small class="text-muted"><?php echo t('custom_dialplan.context_hint', 'Harf, rakam, tire veya alt çizgi.'); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group mb-3">
                                <label for="cdDescription"><?php echo t('custom_dialplan.col_desc', 'Açıklama / Not'); ?></label>
                                <input type="text" class="form-control" name="description" id="cdDescription" maxlength="255" placeholder="<?php echo t('custom_dialplan.desc_placeholder', 'Bu context\'in kullanım amacı...'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group mb-3">
                                <label for="cdSortOrder"><?php echo t('custom_dialplan.col_order', 'Sıra'); ?></label>
                                <input type="number" class="form-control" name="sort_order" id="cdSortOrder" value="10" min="0" max="999">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group mb-3">
                                <label><?php echo t('common.status'); ?></label>
                                <div class="custom-control custom-switch mt-2">
                                    <input type="checkbox" class="custom-control-input" name="is_active" id="cdIsActive" value="1" checked>
                                    <label class="custom-control-label" for="cdIsActive"><?php echo t('common.active'); ?></label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" name="include_in_internal" id="cdIncludeInternal" value="1" checked>
                            <label class="custom-control-label font-weight-bold" for="cdIncludeInternal">
                                <i class="fas fa-link text-info"></i> <?php echo t('custom_dialplan.lbl_include_internal', 'Dahili arama planına dahil et (from-internal-pbx-ortak)'); ?>
                            </label>
                        </div>
                        <small class="form-text text-muted" style="margin-left: 24px;">
                            <?php echo t('custom_dialplan.include_internal_help', 'İşaretliyse, tüm dahili telefonlar ve yumuşak telefonlar bu context içindeki numaraları tuşlayabilir.'); ?>
                        </small>
                    </div>

                    <div class="form-group mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="cdDialplanContent" class="font-weight-bold mb-0">
                                <i class="fas fa-terminal text-secondary"></i> <?php echo t('custom_dialplan.lbl_content', 'Asterisk Arama Planı Kodu'); ?> <span class="text-danger">*</span>
                            </label>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="checkSyntax()">
                                <i class="fas fa-spell-check"></i> <?php echo t('custom_dialplan.btn_check_syntax', 'Sözdizimini Denetle'); ?>
                            </button>
                        </div>
                        <textarea class="form-control font-monospace" name="dialplan_content" id="cdDialplanContent" rows="10" required spellcheck="false" style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 13px; line-height: 1.5; white-space: pre; tab-size: 4;" placeholder="exten => *80.,1,NoOp(...)&#10; same => n,Dial(...)&#10; same => n,Hangup()"></textarea>
                        <div id="syntaxCheckFeedback" class="mt-2" style="display:none;"></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCustomDialplanModal()"><?php echo t('common.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Full Preview of extensions_custom.conf -->
<div class="modal fade" id="previewModal" tabindex="-1" role="dialog" aria-hidden="true" style="display:none;">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-code text-primary"></i> <?php echo t('custom_dialplan.preview_title', '/etc/asterisk/pbx/extensions_custom.conf Önizlemesi'); ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" onclick="closePreviewModal()">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <pre id="previewConfContent" style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; font-size: 12px; max-height: 480px; overflow-y: auto; font-family: 'SFMono-Regular', Consolas, monospace; line-height: 1.45;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePreviewModal()"><?php echo t('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleModuleHelp(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
    }
}

function openCreateCustomDialplanModal() {
    document.getElementById('customDialplanForm').reset();
    document.getElementById('cdEntryId').value = '';
    document.getElementById('customDialplanModalTitle').innerHTML = '<i class="fas fa-code text-primary"></i> ' + <?php echo jsValue(t('custom_dialplan.modal_new_title', 'Yeni Özel Arama Planı Context\'i')); ?>;
    document.getElementById('cdIsActive').checked = true;
    document.getElementById('cdIncludeInternal').checked = true;
    document.getElementById('syntaxCheckFeedback').style.display = 'none';
    document.getElementById('customDialplanModal').style.display = 'block';
    document.getElementById('customDialplanModal').classList.add('show');
}

function openEditCustomDialplanModal(id) {
    fetch('/custom-dialplan?action=get_entry&id=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(data => {
            if (data && data.success && data.entry) {
                const e = data.entry;
                document.getElementById('cdEntryId').value = e.id;
                document.getElementById('cdTitle').value = e.title || '';
                document.getElementById('cdContextName').value = e.context_name || '';
                document.getElementById('cdDescription').value = e.description || '';
                document.getElementById('cdSortOrder').value = e.sort_order || 10;
                document.getElementById('cdIsActive').checked = parseInt(e.is_active, 10) === 1;
                document.getElementById('cdIncludeInternal').checked = parseInt(e.include_in_internal, 10) === 1;
                document.getElementById('cdDialplanContent').value = e.dialplan_content || '';
                document.getElementById('templateSelect').value = '';
                document.getElementById('syntaxCheckFeedback').style.display = 'none';

                document.getElementById('customDialplanModalTitle').innerHTML = '<i class="fas fa-edit text-primary"></i> ' + <?php echo jsValue(t('custom_dialplan.modal_edit_title', 'Özel Arama Planı Düzenle')); ?>;
                document.getElementById('customDialplanModal').style.display = 'block';
                document.getElementById('customDialplanModal').classList.add('show');
            } else {
                alert(<?php echo jsValue(t('common.action_failed')); ?>);
            }
        })
        .catch(err => {
            console.error(err);
            alert(<?php echo jsValue(t('common.action_failed')); ?>);
        });
}

function closeCustomDialplanModal() {
    document.getElementById('customDialplanModal').style.display = 'none';
    document.getElementById('customDialplanModal').classList.remove('show');
}

function openPreviewModal() {
    const pre = document.getElementById('previewConfContent');
    pre.textContent = <?php echo jsValue(t('js.common.loading', 'Yükleniyor...')); ?>;
    document.getElementById('previewModal').style.display = 'block';
    document.getElementById('previewModal').classList.add('show');

    fetch('/custom-dialplan?action=preview_conf')
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                pre.textContent = data.conf;
            } else {
                pre.textContent = <?php echo jsValue(t('common.action_failed')); ?>;
            }
        })
        .catch(err => {
            pre.textContent = 'Error: ' + err.message;
        });
}

function closePreviewModal() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewModal').classList.remove('show');
}

function applyTemplate(key) {
    if (!key) return;
    fetch('/custom-dialplan?action=get_template&key=' + encodeURIComponent(key))
        .then(r => r.json())
        .then(data => {
            if (data && data.success && data.template) {
                const t = data.template;
                if (!document.getElementById('cdTitle').value) {
                    document.getElementById('cdTitle').value = t.title;
                }
                if (!document.getElementById('cdContextName').value) {
                    document.getElementById('cdContextName').value = t.context_name;
                }
                if (!document.getElementById('cdDescription').value) {
                    document.getElementById('cdDescription').value = t.description;
                }
                document.getElementById('cdIncludeInternal').checked = (parseInt(t.include_in_internal, 10) === 1);
                document.getElementById('cdDialplanContent').value = t.content;
                checkSyntax();
            }
        })
        .catch(console.error);
}

function checkSyntax() {
    const content = document.getElementById('cdDialplanContent').value;
    const fb = document.getElementById('syntaxCheckFeedback');

    const formData = new FormData();
    formData.append('content', content);

    fetch('/custom-dialplan?action=validate_syntax', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.success && data.result) {
            const res = data.result;
            fb.style.display = 'block';
            if (res.valid) {
                let html = '<div class="alert alert-success py-2 mb-0"><i class="fas fa-check-circle"></i> ' + <?php echo jsValue(t('custom_dialplan.syntax_valid', 'Sözdizimi geçerli ve dengeli.')); ?> + '</div>';
                if (res.warnings && res.warnings.length > 0) {
                    html += '<div class="alert alert-warning py-2 mt-1 mb-0"><i class="fas fa-exclamation-triangle"></i> ' + res.warnings.join('<br>') + '</div>';
                }
                fb.innerHTML = html;
            } else {
                fb.innerHTML = '<div class="alert alert-danger py-2 mb-0"><i class="fas fa-times-circle"></i> ' + res.errors.join('<br>') + '</div>';
            }
        }
    })
    .catch(console.error);
}
</script>
