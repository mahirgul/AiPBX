<?php
$can_edit = $canEdit ?? false;
$can_delete = $canDelete ?? false;
$ldap = $ldap ?? ['enabled' => false, 'anonymous' => false, 'password' => ''];
$host = ($host ?? '') !== '' ? $host : 'pbx.example.com';
$sourceLabel = fn(string $s): string => $s === 'manual' ? t('phonebook.source_manual') : $s;
?>

<?php if (!empty($notices['message'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($notices['message']); ?></div>
<?php endif; ?>
<?php if (!empty($notices['error'])): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($notices['error']); ?></div>
<?php endif; ?>

<div class="card">
    <?php echo uiPageHeaderOpen('fa-address-book', t('phonebook.title'), 'phonebookHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('phonebookImportModal')">
                    <i class="fas fa-file-csv"></i> <?php echo t('phonebook.import_csv'); ?>
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="openPhonebookModal(null)">
                    <i class="fas fa-plus-circle"></i> <?php echo t('phonebook.new_contact'); ?>
                </button>
            <?php endif; ?>
<?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('phonebookHelpBox', t('phonebook.help_title')); ?>
        <p><?php echo t('phonebook.help_desc'); ?></p>
    <?php echo uiHelpBoxClose(); ?>

    <?php if (!empty($counts)): ?>
        <p class="u-fs-12 text-muted">
            <?php foreach ($counts as $src => $n): ?>
                <span class="badge badge-secondary"><?php echo htmlspecialchars($sourceLabel((string) $src)); ?>: <?php echo (int) $n; ?></span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <?php echo uiTableOpen([
        t('phonebook.col_name'),
        ['label' => t('phonebook.col_company'), 'class' => 'col-hide-mobile'],
        t('phonebook.col_work'),
        ['label' => t('phonebook.col_mobile'), 'class' => 'col-hide-mobile'],
        ['label' => t('phonebook.col_email'), 'class' => 'col-hide-mobile'],
        ['label' => t('phonebook.col_source'), 'class' => 'col-hide-mobile'],
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ], ['id' => 'phonebookTable']); ?>
        <?php if (empty($contacts)): ?>
            <?php echo uiTableEmptyRow(7, t('phonebook.empty'), 'fa-address-book'); ?>
        <?php else: ?>
            <?php foreach ($contacts as $c): $manual = ($c['source'] ?? '') === 'manual'; ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) $c['display_name']); ?></td>
                    <td class="col-hide-mobile"><?php echo htmlspecialchars((string) $c['company']); ?></td>
                    <td><code><?php echo htmlspecialchars((string) ($c['phone_work'] ?: $c['phone_other'])); ?></code></td>
                    <td class="col-hide-mobile"><code><?php echo htmlspecialchars((string) $c['phone_mobile']); ?></code></td>
                    <td class="col-hide-mobile u-fs-12"><?php echo htmlspecialchars((string) $c['email']); ?></td>
                    <td class="col-hide-mobile"><span class="badge badge-secondary"><?php echo htmlspecialchars($sourceLabel((string) $c['source'])); ?></span></td>
                    <td class="text-right">
                        <?php if ($manual && $can_edit): ?>
                            <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick='openPhonebookModal(<?php echo json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="<?php echo t('common.edit'); ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                        <?php endif; ?>
                        <?php if ($manual && $can_delete): ?>
                            <form method="POST" class="u-d-inline" onsubmit="return confirm(<?php echo jsArg(sprintf(t('phonebook.confirm_delete'), (string) $c['display_name'])); ?>);">
                                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                <input type="hidden" name="action" value="delete_contact">
                                <input type="hidden" name="contact_id" value="<?php echo (int) $c['id']; ?>">
                                <button type="submit" class="btn btn-icon btn-danger btn-sm" title="<?php echo t('common.delete'); ?>"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php echo uiTableClose(); ?>
</div>

<div class="card u-mt-16">
    <?php echo uiPageHeader('fa-server', t('phonebook.ldap_title')); ?>
    <p class="u-fs-13"><?php echo t('phonebook.ldap_desc'); ?></p>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
        <input type="hidden" name="action" value="ldap_settings">
        <div class="form-group">
            <label class="form-label u-check-label">
                <input type="checkbox" name="enabled" value="1" class="u-check" <?php echo $ldap['enabled'] ? 'checked' : ''; ?> <?php echo $can_edit ? '' : 'disabled'; ?>>
                <span class="u-fw-600"><?php echo t('phonebook.ldap_enabled'); ?></span>
            </label>
        </div>
        <div class="form-group">
            <label class="form-label u-check-label">
                <input type="checkbox" name="anonymous" value="1" class="u-check" <?php echo $ldap['anonymous'] ? 'checked' : ''; ?> <?php echo $can_edit ? '' : 'disabled'; ?>>
                <span><?php echo t('phonebook.ldap_anonymous'); ?></span>
            </label>
        </div>
        <?php if ($ldap['password'] !== ''): ?>
            <div class="form-group">
                <label class="form-label u-check-label">
                    <input type="checkbox" name="new_password" value="1" class="u-check" <?php echo $can_edit ? '' : 'disabled'; ?>>
                    <span><?php echo t('phonebook.ldap_new_password'); ?></span>
                </label>
            </div>
        <?php endif; ?>
        <?php if ($can_edit): ?>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
        <?php endif; ?>
    </form>

    <?php if ($ldap['enabled']): ?>
        <p class="u-mt-10">
            <?php foreach (['ldap' => 389, 'ldaps' => 636] as $proto => $port): $up = !empty($listening[$proto]); ?>
                <span class="badge <?php echo $up ? 'badge-success' : 'badge-warning'; ?>">
                    <?php echo strtoupper($proto) . ' ' . $port . ': ' . ($up ? t('phonebook.ldap_listening') : t('phonebook.ldap_not_listening')); ?>
                </span>
            <?php endforeach; ?>
        </p>
        <?php echo uiTableOpen([t('phonebook.ldap_setting'), t('phonebook.ldap_value')], ['search' => false]); ?>
            <?php foreach ([
                t('phonebook.ldap_server') => $host,
                t('phonebook.ldap_port') => '389 (LDAP, StartTLS) / 636 (LDAPS)',
                t('phonebook.ldap_base') => PhonebookService::BASE_DN,
                t('phonebook.ldap_user') => PhonebookService::BIND_DN,
                t('phonebook.ldap_password') => $can_edit ? $ldap['password'] : ($ldap['password'] !== '' ? '••••••••' : ''),
                t('phonebook.ldap_name_filter') => '(|(cn=%*)(sn=%*))',
                t('phonebook.ldap_number_filter') => '(|(telephoneNumber=%)(mobile=%)(homePhone=%))',
                t('phonebook.ldap_name_attr') => 'cn',
                t('phonebook.ldap_number_attrs') => 'telephoneNumber mobile homePhone',
            ] as $k => $v): ?>
                <tr><td><?php echo htmlspecialchars($k); ?></td><td><code><?php echo htmlspecialchars($v); ?></code></td></tr>
            <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
        <p class="u-fs-12 text-muted u-mt-10"><i class="fas fa-shield-alt"></i> <?php echo t('phonebook.ldap_firewall'); ?></p>
    <?php endif; ?>
</div>

<?php if ($can_edit): ?>
<div class="modal-overlay" id="phonebookModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title" id="phonebookModalTitle"><i class="fas fa-address-book"></i> <?php echo t('phonebook.new_contact'); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('phonebookModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="save_contact">
                <input type="hidden" name="contact_id" id="pb_id" value="">
                <?php foreach ([
                    'display_name' => t('phonebook.col_name'), 'first_name' => t('phonebook.first_name'), 'last_name' => t('phonebook.last_name'),
                    'company' => t('phonebook.col_company'), 'phone_work' => t('phonebook.col_work'), 'phone_mobile' => t('phonebook.col_mobile'),
                    'phone_other' => t('phonebook.col_other'), 'email' => t('phonebook.col_email'),
                ] as $f => $label): ?>
                    <div class="form-group u-mt-10">
                        <label class="form-label" for="pb_<?php echo $f; ?>"><?php echo $label; ?></label>
                        <input type="<?php echo $f === 'email' ? 'email' : (str_starts_with($f, 'phone_') ? 'tel' : 'text'); ?>" name="<?php echo $f; ?>" id="pb_<?php echo $f; ?>" class="form-control">
                    </div>
                <?php endforeach; ?>
                <small class="u-hint u-fs-11"><?php echo t('phonebook.form_hint'); ?></small>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('phonebookModal')"); ?>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="phonebookImportModal">
    <div class="modal-card u-maxw-480">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-file-csv"></i> <?php echo t('phonebook.import_csv'); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('phonebookImportModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="import_csv">
                <div class="form-group">
                    <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" class="form-control" required>
                    <small class="u-hint u-fs-11"><?php echo t('phonebook.csv_format'); ?></small>
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('phonebookImportModal')"); ?>
            </form>
        </div>
    </div>
</div>

<script>
function openPhonebookModal(c) {
    const fields = <?php echo json_encode(PhonebookService::FIELDS); ?>;
    document.getElementById('pb_id').value = c ? c.id : '';
    fields.forEach(f => { document.getElementById('pb_' + f).value = c ? (c[f] || '') : ''; });
    document.getElementById('phonebookModalTitle').lastChild.textContent = ' ' + (c ? <?php echo json_encode(t('phonebook.edit_contact')); ?> : <?php echo json_encode(t('phonebook.new_contact')); ?>);
    UIHelper.openOverlayModal('phonebookModal');
}
</script>
<?php endif; ?>
