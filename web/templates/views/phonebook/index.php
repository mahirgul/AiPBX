<?php
$can_edit = $canEdit ?? false;
$can_delete = $canDelete ?? false;
$can_admin = $canAdmin ?? false;
$ldap = $ldap ?? ['enabled' => false, 'anonymous' => false, 'password' => ''];
$host = ($host ?? '') !== '' ? $host : 'pbx.example.com';
$sourceLabel = fn(string $s): string => $s === 'manual' ? t('phonebook.source_manual') : $s;
$pbTabs = [
    'contacts' => ['label' => t('phonebook.title'), 'icon' => 'fa-address-book'],
];
if ($can_admin) {
    $pbTabs['match'] = ['label' => t('phonebook.match_title'), 'icon' => 'fa-equals'];
    $pbTabs['ldap'] = ['label' => t('phonebook.ldap_title'), 'icon' => 'fa-server'];
}
$activePbTab = (string) ($_GET['tab'] ?? 'contacts');
if (!isset($pbTabs[$activePbTab])) {
    $activePbTab = 'contacts';
}
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
                <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('phonebookExtDbModal')">
                    <i class="fas fa-database"></i> <?php echo t('phonebook.import_extdb'); ?>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('phonebookImportModal')">
                    <i class="fas fa-file-csv"></i> <?php echo t('phonebook.import_csv'); ?>
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="openPhonebookModal(null)">
                    <i class="fas fa-plus-circle"></i> <?php echo t('phonebook.new_contact'); ?>
                </button>
            <?php endif; ?>
<?php echo uiPageHeaderClose(); ?>
<?php if (count($pbTabs) > 1): ?>
<?php echo uiTabs($pbTabs, $activePbTab); ?>
<?php endif; ?>

    <?php echo uiHelpBoxOpen('phonebookHelpBox', t('phonebook.help_title')); ?>
        <p><?php echo t('phonebook.help_desc'); ?></p>
    <?php echo uiHelpBoxClose(); ?>

    <div data-tab-pane="contacts"<?php echo $activePbTab === 'contacts' ? '' : ' hidden'; ?>>
    <?php if (!empty($counts)): ?>
        <p class="u-fs-12 text-muted">
            <?php foreach ($counts as $src => $n): ?>
                <span class="badge badge-secondary"><?php echo htmlspecialchars($sourceLabel((string) $src)); ?>: <?php echo (int) $n; ?></span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <?php echo uiTableOpen([
        t('phonebook.col_name'),
        ['label' => t('phonebook.customer_id'), 'class' => 'col-hide-mobile'],
        ['label' => t('phonebook.col_company'), 'class' => 'col-hide-mobile'],
        t('phonebook.col_numbers'),
        ['label' => t('phonebook.col_email'), 'class' => 'col-hide-mobile'],
        ['label' => t('phonebook.col_source'), 'class' => 'col-hide-mobile'],
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ], ['id' => 'phonebookTable']); ?>
        <?php if (empty($contacts)): ?>
            <?php echo uiTableEmptyRow(7, t('phonebook.empty'), 'fa-address-book'); ?>
        <?php else: ?>
            <?php foreach ($contacts as $c): $manual = ($c['source'] ?? '') === 'manual'; ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars((string) $c['display_name']); ?>
                        <?php if (!empty($c['is_vip'])): ?>
                            <span class="badge badge-warning"><?php echo t('phonebook.vip'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="col-hide-mobile"><?php echo ($c['external_id'] ?? '') !== '' ? '<code>' . htmlspecialchars((string) $c['external_id']) . '</code>' : '—'; ?></td>
                    <td class="col-hide-mobile"><?php echo htmlspecialchars((string) $c['company']); ?></td>
                    <td>
                        <?php foreach ($c['numbers'] ?? [] as $n): ?>
                            <div class="u-fs-12"><span class="text-muted"><?php echo htmlspecialchars(t('phonebook.label_' . $n['label'])); ?>:</span> <code><?php echo htmlspecialchars($n['number']); ?></code></div>
                        <?php endforeach; ?>
                        <?php if (($c['sub_extension'] ?? '') !== ''): ?>
                            <div class="u-fs-12"><span class="text-muted"><?php echo htmlspecialchars(t('phonebook.sub_extension')); ?>:</span> <code><?php echo htmlspecialchars((string) $c['sub_extension']); ?></code></div>
                        <?php endif; ?>
                    </td>
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

<?php if ($can_admin): ?>
<div data-tab-pane="match"<?php echo $activePbTab === 'match' ? '' : ' hidden'; ?>>
    <p class="u-fs-13"><?php echo t('phonebook.match_desc'); ?></p>
    <form method="POST" class="u-d-flex u-ai-center u-gap-8 u-wrap">
        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
        <input type="hidden" name="action" value="match_digits">
        <select name="caller_match_digits" class="form-control form-control-sm u-w-auto" <?php echo $can_admin ? '' : 'disabled'; ?>>
            <?php foreach (PhonebookService::MATCH_DIGITS as $d): ?>
                <option value="<?php echo $d; ?>" <?php echo $d === ($matchDigits ?? 10) ? 'selected' : ''; ?>><?php echo $d === 0 ? t('phonebook.match_whole') : sprintf(t('phonebook.match_last'), $d); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="u-check-label u-fs-13">
            <input type="checkbox" name="auto_save_callers" value="1" class="u-check" <?php echo !empty($autoSave) ? 'checked' : ''; ?>>
            <span><?php echo t('phonebook.auto_save_callers'); ?></span>
        </label>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
    </form>
</div>

<div data-tab-pane="ldap"<?php echo $activePbTab === 'ldap' ? '' : ' hidden'; ?>>
    <p class="u-fs-13"><?php echo t('phonebook.ldap_desc'); ?></p>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
        <input type="hidden" name="action" value="ldap_settings">
        <div class="form-group">
            <label class="form-label u-check-label">
                <input type="checkbox" name="enabled" value="1" class="u-check" <?php echo $ldap['enabled'] ? 'checked' : ''; ?> <?php echo $can_admin ? '' : 'disabled'; ?>>
                <span class="u-fw-600"><?php echo t('phonebook.ldap_enabled'); ?></span>
            </label>
        </div>
        <div class="form-group">
            <label class="form-label u-check-label">
                <input type="checkbox" name="anonymous" value="1" class="u-check" <?php echo $ldap['anonymous'] ? 'checked' : ''; ?> <?php echo $can_admin ? '' : 'disabled'; ?>>
                <span><?php echo t('phonebook.ldap_anonymous'); ?></span>
            </label>
        </div>
        <?php if ($ldap['password'] !== ''): ?>
            <div class="form-group">
                <label class="form-label u-check-label">
                    <input type="checkbox" name="new_password" value="1" class="u-check" <?php echo $can_admin ? '' : 'disabled'; ?>>
                    <span><?php echo t('phonebook.ldap_new_password'); ?></span>
                </label>
            </div>
        <?php endif; ?>
        <?php if ($can_admin): ?>
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
                t('phonebook.ldap_password') => $can_admin ? $ldap['password'] : ($ldap['password'] !== '' ? '••••••••' : ''),
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
            <form method="POST" action="/phonebook" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="save_contact">
                <input type="hidden" name="contact_id" id="pb_id" value="">
                <?php foreach ([
                    'external_id' => t('phonebook.customer_id'), 'display_name' => t('phonebook.col_name'), 'first_name' => t('phonebook.first_name'), 'last_name' => t('phonebook.last_name'),
                    'company' => t('phonebook.col_company'), 'sub_extension' => t('phonebook.sub_extension'), 'email' => t('phonebook.col_email'),
                ] as $f => $label): ?>
                    <div class="form-group u-mt-10">
                        <label class="form-label" for="pb_<?php echo $f; ?>"><?php echo $label; ?></label>
                        <input type="<?php echo $f === 'email' ? 'email' : 'text'; ?>" name="<?php echo $f; ?>" id="pb_<?php echo $f; ?>" class="form-control"<?php echo $f === 'external_id' ? ' maxlength="191"' : ($f === 'sub_extension' ? ' maxlength="32"' : ''); ?>>
                    </div>
                <?php endforeach; ?>
                <div class="form-group u-mt-10">
                    <label class="u-d-flex u-gap-8">
                        <input type="checkbox" name="is_vip" id="pb_is_vip" value="1" class="u-check">
                        <span><?php echo t('phonebook.vip'); ?></span>
                    </label>
                    <small class="u-hint"><?php echo t('phonebook.vip_help'); ?></small>
                </div>
                <div class="form-group u-mt-10">
                    <label class="form-label"><?php echo t('phonebook.col_numbers'); ?></label>
                    <div id="pb_numbers"></div>
                    <template id="pb_number_row">
                        <div class="u-d-flex u-gap-8 u-mb-8 pb-number-row">
                            <select name="numbers_label[]" class="form-control u-w-140">
                                <?php foreach (PhonebookService::LABELS as $l): ?>
                                    <option value="<?php echo $l; ?>"><?php echo htmlspecialchars(t('phonebook.label_' . $l)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="tel" name="numbers_value[]" class="form-control" maxlength="40">
                            <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="phonebookRemoveNumber(this)" title="<?php echo t('common.delete'); ?>"><i class="fas fa-times"></i></button>
                        </div>
                    </template>
                    <button type="button" class="btn btn-secondary btn-sm" id="pb_add_number" onclick="phonebookAddNumber('', '')" data-max="<?php echo PhonebookService::MAX_NUMBERS; ?>"><i class="fas fa-plus"></i> <?php echo t('phonebook.add_number'); ?></button>
                </div>
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

<?php $extCfg = $extdb_settings ?? ['db_id' => 0, 'sql' => '', 'enabled' => false, 'query_key' => '']; ?>
<div class="modal-overlay" id="phonebookExtDbModal">
    <div class="modal-card u-maxw-480">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-database"></i> <?php echo t('phonebook.import_extdb'); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('phonebookExtDbModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="import_external_db">
                <div class="form-group">
                    <label class="form-label" for="extdb_saved_query_key"><?php echo htmlspecialchars(t('external_dbs.query_key')); ?></label>
                    <select name="saved_query_key" id="extdb_saved_query_key" class="form-control" required>
                        <option value=""><?php echo htmlspecialchars(t('external_dbs.query_select_public')); ?></option>
                        <?php foreach (($saved_queries ?? []) as $sq): ?>
                            <option value="<?php echo htmlspecialchars((string) $sq['query_key']); ?>" <?php echo (($extCfg['query_key'] ?? '') === (string) $sq['query_key']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $sq['query_key'] . ' — ' . (string) $sq['description'] . ' (' . (string) $sq['connection_name'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="u-hint u-fs-11"><a href="/external-dbs?tab=queries"><?php echo htmlspecialchars(t('external_dbs.tab_queries')); ?></a></small>
                </div>
                <?php if ($can_admin): ?>
                <div class="form-group">
                    <label class="u-d-inline-flex u-ai-center u-gap-6 u-fs-12 u-pointer">
                        <input type="checkbox" name="nightly_sync" value="1" class="u-accent" <?php echo !empty($extCfg['enabled']) ? 'checked' : ''; ?>>
                        <span><?php echo t('phonebook.extdb_nightly_sync'); ?></span>
                    </label>
                </div>
                <?php endif; ?>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('phonebookExtDbModal')"); ?>
            </form>
        </div>
    </div>
</div>

<script>window.PHONEBOOK = <?php echo json_encode([
    'fields' => array_merge(['external_id'], array_values(array_diff(PhonebookService::FIELDS, ['phone_work', 'phone_mobile', 'phone_other']))),
    'add' => (string) ($prefill ?? ''),
], JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<script src="<?php echo asset('/assets/js/phonebook.js'); ?>"></script>
<?php endif; ?>
