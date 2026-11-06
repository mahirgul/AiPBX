<?php
/**
 * View: Security → GDPR & Data Privacy (EN) / KVKK ve Veri Gizliliği (TR)
 *
 * @var string $csrf
 * @var array<string, mixed> $settings
 * @var array<string, array<string, mixed>> $profiles
 * @var array<string, mixed> $overview
 * @var string $subjectQuery
 * @var array<string, mixed>|null $subjectResult
 */
$activeTab = 'notice';
if ($subjectQuery !== '' || isset($_POST['erase_subject'])) {
    $activeTab = 'subject';
} elseif (isset($_POST['save_retention']) || isset($_POST['run_purge'])) {
    $activeTab = 'retention';
} elseif (isset($_POST['save_compliance'])) {
    $activeTab = 'compliance';
}
$activeProfileCode = $profiles[$settings['jurisdiction_profile']]['code'] ?? 'GDPR / KVKK';
?>

<div class="card tabbed-page">
    <?php echo uiPageHeaderOpen('fa-user-lock', htmlspecialchars(t('privacy.header_title')), 'privacyHelpBox'); ?>
<span class="badge badge-primary">
            <i class="fas fa-scale-balanced"></i>
            <?php echo htmlspecialchars($activeProfileCode); ?>
        </span>
        <span class="badge <?php echo $settings['notice_mode'] !== 'off' ? 'badge-success' : 'badge-secondary'; ?>">
            <i class="fas fa-bullhorn"></i>
            <?php echo htmlspecialchars(t('privacy.badge_notice') . ': ' . t('privacy.mode_' . $settings['notice_mode'])); ?>
        </span>
        <span class="badge <?php echo !empty($settings['retention_enabled']) ? 'badge-success' : 'badge-warning'; ?>">
            <i class="fas fa-broom"></i>
            <?php echo htmlspecialchars(t('privacy.badge_retention') . ': ' . (!empty($settings['retention_enabled']) ? t('common.active') : t('common.inactive'))); ?>
        </span>
        <span class="badge <?php echo !empty($settings['block_cloud_ai']) ? 'badge-primary' : 'badge-secondary'; ?>">
            <i class="fas fa-shield-alt"></i>
            <?php echo htmlspecialchars(t('privacy.badge_cloud_lock') . ': ' . (!empty($settings['block_cloud_ai']) ? t('common.active') : t('common.inactive'))); ?>
        </span>
<?php echo uiPageHeaderActions(); ?>
<?php echo uiPageHeaderClose(); ?>
<?php echo uiHelpBoxOpen('privacyHelpBox', t('common.module_guide')); ?><?php echo htmlspecialchars(t('privacy.subtitle')); ?><?php echo uiHelpBoxClose(); ?>

<!-- Global Regulatory Jurisdiction Preset Bar -->
<div class="card u-mb-20 u-p-16-20">
    <form method="post" action="/privacy" class="u-d-flex u-ai-center u-jc-space-between u-wrap u-gap-12 u-m-0">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="apply_profile" value="1">
        <div class="u-flex-1 u-minw-260">
            <div class="u-fw-700 u-fs-14 u-d-flex u-ai-center u-gap-8">
                <i class="fas fa-earth-americas u-primary"></i>
                <?php echo htmlspecialchars(t('privacy.profile_bar_title')); ?>
            </div>
            <div class="u-muted u-fs-12"><?php echo htmlspecialchars(t('privacy.profile_bar_help')); ?></div>
        </div>
        <div class="u-d-flex u-gap-10 u-ai-center u-wrap">
            <select name="jurisdiction_profile" class="form-control u-minw-280">
                <?php foreach ($profiles as $pKey => $pMeta): ?>
                    <option value="<?php echo htmlspecialchars($pKey); ?>"<?php echo $settings['jurisdiction_profile'] === $pKey ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars(t('privacy.profile_' . $pKey)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-wand-magic-sparkles"></i> <?php echo htmlspecialchars(t('privacy.btn_apply_profile')); ?>
            </button>
        </div>
    </form>
</div>

<!-- Navigation Tabs -->
<div class="sub-tab-nav">
    <button type="button" class="sub-tab-btn<?php echo $activeTab === 'notice' ? ' active' : ''; ?>" data-privacy-tab="notice" onclick="switchPrivacyTab('notice')">
        <i class="fas fa-phone-volume"></i> <?php echo htmlspecialchars(t('privacy.tab_notice')); ?>
    </button>
    <button type="button" class="sub-tab-btn<?php echo $activeTab === 'retention' ? ' active' : ''; ?>" data-privacy-tab="retention" onclick="switchPrivacyTab('retention')">
        <i class="fas fa-clock-rotate-left"></i> <?php echo htmlspecialchars(t('privacy.tab_retention')); ?>
    </button>
    <button type="button" class="sub-tab-btn<?php echo $activeTab === 'subject' ? ' active' : ''; ?>" data-privacy-tab="subject" onclick="switchPrivacyTab('subject')">
        <i class="fas fa-user-eraser"></i> <?php echo htmlspecialchars(t('privacy.tab_subject')); ?>
    </button>
    <button type="button" class="sub-tab-btn<?php echo $activeTab === 'compliance' ? ' active' : ''; ?>" data-privacy-tab="compliance" onclick="switchPrivacyTab('compliance')">
        <i class="fas fa-clipboard-check"></i> <?php echo htmlspecialchars(t('privacy.tab_compliance')); ?>
    </button>
</div>

<!-- TAB 1: Pre-Call Disclosure, Opt-In/Opt-Out, FCC Beep & PCI-DSS -->
<div id="privacy-panel-notice" class="u-pt-16" style="display:<?php echo $activeTab === 'notice' ? 'block' : 'none'; ?>;">
    <div class="grid-2 u-d-grid u-gtc-repeat-auto-fit-minmax-340-1fr u-gap-20">
        <div class="card">
            <h3 class="u-mt-0"><i class="fas fa-bullhorn u-primary"></i> <?php echo htmlspecialchars(t('privacy.notice_card_title')); ?></h3>
            <p class="u-muted u-fs-13"><?php echo htmlspecialchars(t('privacy.notice_card_help')); ?></p>

            <form method="post" action="/privacy">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="save_notice" value="1">

                <div class="form-group u-mb-14">
                    <label><?php echo htmlspecialchars(t('privacy.field_notice_mode')); ?></label>
                    <select name="notice_mode" class="form-control">
                        <option value="off"<?php echo $settings['notice_mode'] === 'off' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('privacy.mode_off')); ?></option>
                        <option value="notice"<?php echo $settings['notice_mode'] === 'notice' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('privacy.mode_notice')); ?></option>
                        <option value="optout"<?php echo $settings['notice_mode'] === 'optout' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('privacy.mode_optout')); ?></option>
                        <option value="optin"<?php echo $settings['notice_mode'] === 'optin' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('privacy.mode_optin')); ?></option>
                    </select>
                </div>

                <div class="form-group u-mb-14">
                    <label><?php echo htmlspecialchars(t('privacy.field_notice_sound')); ?></label>
                    <select name="notice_sound_id" class="form-control">
                        <option value="0">— <?php echo htmlspecialchars(t('privacy.select_sound')); ?> —</option>
                        <?php foreach ($overview['sounds'] as $snd): ?>
                            <option value="<?php echo (int)$snd['id']; ?>"<?php echo (int)$settings['notice_sound_id'] === (int)$snd['id'] ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($snd['title'] . (!empty($snd['language']) ? ' [' . $snd['language'] . ']' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="u-d-grid u-gtc-1fr-1fr-1fr u-gap-12 u-mb-16">
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('privacy.field_optout_digit')); ?></label>
                        <select name="optout_digit" class="form-control">
                            <?php foreach (PrivacyService::OPTOUT_DIGITS as $dg): ?>
                                <option value="<?php echo htmlspecialchars($dg); ?>"<?php echo $settings['optout_digit'] === $dg ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dg); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('privacy.field_optout_timeout')); ?></label>
                        <input type="number" name="optout_timeout" min="1" max="10" class="form-control" value="<?php echo (int)$settings['optout_timeout']; ?>">
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('privacy.field_recording_beep')); ?></label>
                        <select name="recording_beep_sec" class="form-control">
                            <option value="0"<?php echo (int)$settings['recording_beep_sec'] === 0 ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('privacy.beep_off')); ?></option>
                            <option value="15"<?php echo (int)$settings['recording_beep_sec'] === 15 ? ' selected' : ''; ?>>15 s (FCC / US)</option>
                            <option value="30"<?php echo (int)$settings['recording_beep_sec'] === 30 ? ' selected' : ''; ?>>30 s</option>
                        </select>
                    </div>
                </div>

                <div class="form-group u-mb-16">
                    <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fw-600">
                        <input type="checkbox" name="pci_pause_enabled" value="1"<?php echo !empty($settings['pci_pause_enabled']) ? ' checked' : ''; ?>>
                        <span><?php echo htmlspecialchars(t('privacy.field_pci_pause')); ?></span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('common.save')); ?>
                </button>
            </form>
        </div>

        <div class="card">
            <h3 class="u-mt-0"><i class="fas fa-file-audio u-primary"></i> <?php echo htmlspecialchars(t('privacy.sample_scripts_title')); ?></h3>
            <p class="u-muted u-fs-13"><?php echo htmlspecialchars(t('privacy.sample_scripts_help')); ?></p>

            <div class="u-mb-12">
                <label class="u-fs-12 u-strong">TR — KVKK Aydınlatma ve Kayıtsız Görüşme (Opt-Out — Tuş 9):</label>
                <textarea class="form-control u-fs-12" rows="3" readonly onclick="this.select()"><?php echo htmlspecialchars(t('privacy.sample_script_tr')); ?></textarea>
            </div>

            <div class="u-mb-12">
                <label class="u-fs-12 u-strong">EN — GDPR / CCPA Opt-Out Notice (Press 9 for Unrecorded Call):</label>
                <textarea class="form-control u-fs-12" rows="3" readonly onclick="this.select()"><?php echo htmlspecialchars(t('privacy.sample_script_en')); ?></textarea>
            </div>

            <div class="u-mb-14">
                <label class="u-fs-12 u-strong">EN — Strict GDPR / US Two-Party Consent Opt-In (Press 1 to Allow Recording):</label>
                <textarea class="form-control u-fs-12" rows="3" readonly onclick="this.select()"><?php echo htmlspecialchars(t('privacy.sample_script_optin_en')); ?></textarea>
            </div>

            <div class="u-d-flex u-gap-8 u-wrap">
                <a href="/sounds" class="btn btn-secondary btn-sm"><i class="fas fa-music"></i> <?php echo htmlspecialchars(t('sidebar.item_sounds')); ?></a>
                <a href="/ai-tts" class="btn btn-secondary btn-sm"><i class="fas fa-comment-dots"></i> <?php echo htmlspecialchars(t('sidebar.item_ai_tts')); ?></a>
            </div>
        </div>
    </div>
</div>

<!-- TAB 2: Data Retention & Auto-Purge -->
<div id="privacy-panel-retention" class="u-pt-16" style="display:<?php echo $activeTab === 'retention' ? 'block' : 'none'; ?>;">
    <div class="card">
        <div class="u-d-flex u-jc-space-between u-ai-flex-start u-wrap u-gap-12 u-mb-14">
            <div>
                <h3 class="u-m-0"><i class="fas fa-clock-rotate-left u-primary"></i> <?php echo htmlspecialchars(t('privacy.retention_card_title')); ?></h3>
                <p class="u-muted u-fs-13 u-m-4-0-0-0"><?php echo htmlspecialchars(t('privacy.retention_card_help')); ?></p>
            </div>
            <form method="post" action="/privacy" onsubmit="return confirm(<?php echo json_encode(t('privacy.confirm_purge_now')); ?>);">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="run_purge" value="1">
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="fas fa-broom"></i> <?php echo htmlspecialchars(t('privacy.btn_purge_now')); ?>
                </button>
            </form>
        </div>

        <?php if ($settings['last_purge_at'] !== ''): ?>
            <div class="alert alert-info u-fs-12 u-mb-16">
                <i class="fas fa-check-circle"></i>
                <strong><?php echo htmlspecialchars(t('privacy.last_purge_label')); ?>:</strong>
                <?php echo htmlspecialchars($settings['last_purge_at'] . ' — ' . $settings['last_purge_summary']); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/privacy">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="save_retention" value="1">

            <div class="form-group u-mb-16">
                <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fw-600">
                    <input type="checkbox" name="retention_enabled" value="1"<?php echo !empty($settings['retention_enabled']) ? ' checked' : ''; ?>>
                    <span><?php echo htmlspecialchars(t('privacy.field_retention_enabled')); ?></span>
                </label>
            </div>

            <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-16 u-mb-16">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('privacy.field_ret_recordings')); ?></label>
                    <input type="number" name="ret_recordings_days" min="0" max="3650" class="form-control" value="<?php echo (int)$settings['ret_recordings_days']; ?>">
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('privacy.field_ret_voicemail')); ?></label>
                    <input type="number" name="ret_voicemail_days" min="0" max="3650" class="form-control" value="<?php echo (int)$settings['ret_voicemail_days']; ?>">
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('privacy.field_ret_fax')); ?></label>
                    <input type="number" name="ret_fax_days" min="0" max="3650" class="form-control" value="<?php echo (int)$settings['ret_fax_days']; ?>">
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('privacy.field_ret_chat')); ?></label>
                    <input type="number" name="ret_chat_days" min="0" max="3650" class="form-control" value="<?php echo (int)$settings['ret_chat_days']; ?>">
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('privacy.field_ret_cdr')); ?></label>
                    <input type="number" name="ret_cdr_days" min="0" max="3650" class="form-control" value="<?php echo (int)$settings['ret_cdr_days']; ?>">
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('privacy.field_ret_cdr_action')); ?></label>
                    <select name="ret_cdr_action" class="form-control">
                        <option value="anonymize"<?php echo $settings['ret_cdr_action'] === 'anonymize' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('privacy.cdr_action_anonymize')); ?></option>
                        <option value="delete"<?php echo $settings['ret_cdr_action'] === 'delete' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('privacy.cdr_action_delete')); ?></option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('common.save')); ?>
            </button>
        </form>
    </div>
</div>

<!-- TAB 3: Subject Lookup (SAR) & Right to Erasure -->
<div id="privacy-panel-subject" class="u-pt-16" style="display:<?php echo $activeTab === 'subject' ? 'block' : 'none'; ?>;">
    <div class="card u-mb-18">
        <h3 class="u-mt-0"><i class="fas fa-user-eraser u-primary"></i> <?php echo htmlspecialchars(t('privacy.subject_card_title')); ?></h3>
        <p class="u-muted u-fs-13"><?php echo htmlspecialchars(t('privacy.subject_card_help')); ?></p>

        <form method="get" action="/privacy" class="u-d-flex u-gap-10 u-maxw-520 u-ai-center">
            <input type="text" name="subject" class="form-control" placeholder="<?php echo htmlspecialchars(t('privacy.subject_placeholder')); ?>" value="<?php echo htmlspecialchars($subjectQuery); ?>" required>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> <?php echo htmlspecialchars(t('common.search')); ?>
            </button>
        </form>
    </div>

    <?php if ($subjectQuery !== ''): ?>
        <?php if ($subjectResult === null): ?>
            <div class="alert alert-warning"><?php echo htmlspecialchars(t('privacy.err_subject_short')); ?></div>
        <?php else: ?>
            <div class="card">
                <div class="u-d-flex u-jc-space-between u-ai-center u-wrap u-gap-12 u-mb-16">
                    <h3 class="u-m-0">
                        <?php echo htmlspecialchars(t('privacy.subject_results_for') . ': ' . $subjectResult['query']); ?>
                    </h3>
                    <div class="u-d-flex u-gap-10 u-wrap">
                        <form method="post" action="/privacy" data-no-spa="true">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="subject_number" value="<?php echo htmlspecialchars($subjectResult['query']); ?>">
                            <input type="hidden" name="export_subject" value="1">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i class="fas fa-file-export"></i> <?php echo htmlspecialchars(t('privacy.btn_export_sar')); ?>
                            </button>
                        </form>
                        <form method="post" action="/privacy" onsubmit="return confirm(<?php echo json_encode(t('privacy.confirm_erase_subject')); ?>);">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="subject_number" value="<?php echo htmlspecialchars($subjectResult['query']); ?>">
                            <input type="hidden" name="erase_subject" value="1">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash-can"></i> <?php echo htmlspecialchars(t('privacy.btn_erase_subject')); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-150-1fr u-gap-12 u-mb-16">
                    <div class="u-p-12 u-bd-1-solid-border-color u-r-8">
                        <div class="u-muted u-fs-12"><?php echo htmlspecialchars(t('privacy.stat_cdrs')); ?></div>
                        <div class="u-fs-20 u-fw-700"><?php echo (int)$subjectResult['cdr_count']; ?></div>
                    </div>
                    <div class="u-p-12 u-bd-1-solid-border-color u-r-8">
                        <div class="u-muted u-fs-12"><?php echo htmlspecialchars(t('privacy.stat_recordings')); ?></div>
                        <div class="u-fs-20 u-fw-700"><?php echo (int)$subjectResult['recording_count']; ?></div>
                    </div>
                    <div class="u-p-12 u-bd-1-solid-border-color u-r-8">
                        <div class="u-muted u-fs-12"><?php echo htmlspecialchars(t('privacy.stat_voicemails')); ?></div>
                        <div class="u-fs-20 u-fw-700"><?php echo (int)$subjectResult['voicemail_count']; ?></div>
                    </div>
                    <div class="u-p-12 u-bd-1-solid-border-color u-r-8">
                        <div class="u-muted u-fs-12"><?php echo htmlspecialchars(t('privacy.stat_faxes')); ?></div>
                        <div class="u-fs-20 u-fw-700"><?php echo (int)$subjectResult['fax_count']; ?></div>
                    </div>
                    <div class="u-p-12 u-bd-1-solid-border-color u-r-8">
                        <div class="u-muted u-fs-12"><?php echo htmlspecialchars(t('privacy.stat_signaling')); ?></div>
                        <div class="u-fs-20 u-fw-700"><?php echo (int)$subjectResult['signaling_count']; ?></div>
                    </div>
                </div>

                <?php if (!empty($subjectResult['cdrs'])): ?>
                    <?php echo uiTableOpen([
                        htmlspecialchars(t('common.date')),
                        htmlspecialchars(t('cdr_reports.col_src')),
                        htmlspecialchars(t('cdr_reports.col_dst')),
                        htmlspecialchars(t('common.status')),
                        htmlspecialchars(t('cdr_reports.col_duration')),
                    ]); ?>
                                <?php foreach ($subjectResult['cdrs'] as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($row['calldate'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($row['src'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($row['dst'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($row['disposition'] ?? '')); ?></td>
                                        <td><?php echo (int)($row['billsec'] ?? 0); ?>s</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php echo uiTableClose(); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- TAB 4: Technical Safeguards & Cross-Border Cloud AI Lock -->
<div id="privacy-panel-compliance" class="u-pt-16" style="display:<?php echo $activeTab === 'compliance' ? 'block' : 'none'; ?>;">
    <div class="grid-2 u-d-grid u-gtc-repeat-auto-fit-minmax-340-1fr u-gap-20 u-mb-20">
        <div class="card">
            <h3 class="u-mt-0"><i class="fas fa-globe u-primary"></i> <?php echo htmlspecialchars(t('privacy.cloud_lock_title')); ?></h3>
            <p class="u-muted u-fs-13"><?php echo htmlspecialchars(t('privacy.cloud_lock_help')); ?></p>

            <form method="post" action="/privacy">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="save_compliance" value="1">

                <div class="form-group u-mb-16">
                    <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fw-600">
                        <input type="checkbox" name="block_cloud_ai" value="1"<?php echo !empty($settings['block_cloud_ai']) ? ' checked' : ''; ?>>
                        <span><?php echo htmlspecialchars(t('privacy.field_block_cloud_ai')); ?></span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('common.save')); ?>
                </button>
            </form>
        </div>

        <div class="card">
            <h3 class="u-mt-0"><i class="fas fa-headphones u-primary"></i> <?php echo htmlspecialchars(t('privacy.listeners_title')); ?></h3>
            <p class="u-muted u-fs-13"><?php echo htmlspecialchars(t('privacy.listeners_help')); ?></p>
            <?php echo uiTableOpen([
                htmlspecialchars(t('common.username')),
                htmlspecialchars(t('common.name')),
                htmlspecialchars(t('common.role')),
            ]); ?>
                        <?php foreach ($overview['recording_listeners'] as $u): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars((string)$u['username']); ?></code></td>
                                <td><?php echo htmlspecialchars((string)($u['full_name'] ?? '')); ?></td>
                                <td><span class="badge badge-secondary"><?php echo htmlspecialchars((string)$u['role']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php echo uiTableClose(); ?>
        </div>
    </div>

    <div class="card">
        <h3 class="u-mt-0"><i class="fas fa-shield-alt u-primary"></i> <?php echo htmlspecialchars(t('privacy.audit_trail_title')); ?></h3>
        <?php if (empty($overview['recent_audits'])): ?>
            <p class="u-muted u-fs-13"><?php echo htmlspecialchars(t('privacy.audit_empty')); ?></p>
        <?php else: ?>
            <?php echo uiTableOpen([
                htmlspecialchars(t('common.date')),
                htmlspecialchars(t('common.action')),
                htmlspecialchars(t('common.username')),
                htmlspecialchars(t('common.details')),
            ]); ?>
                        <?php foreach ($overview['recent_audits'] as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($a['created_at'] ?? '')); ?></td>
                                <td><code><?php echo htmlspecialchars((string)($a['action'] ?? '')); ?></code></td>
                                <td><?php echo htmlspecialchars((string)($a['username'] ?? '')); ?></td>
                                <td class="u-fs-12"><?php echo htmlspecialchars((string)($a['details'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php echo uiTableClose(); ?>
        <?php endif; ?>
    </div>
</div>

</div><!-- /tabbed-page -->

<script>
function switchPrivacyTab(tab) {
    var tabs = ['notice', 'retention', 'subject', 'compliance'];
    for (var i = 0; i < tabs.length; i++) {
        var panel = document.getElementById('privacy-panel-' + tabs[i]);
        if (panel) panel.style.display = (tabs[i] === tab) ? 'block' : 'none';
    }
    var btns = document.querySelectorAll('[data-privacy-tab]');
    for (var j = 0; j < btns.length; j++) {
        var active = btns[j].getAttribute('data-privacy-tab') === tab;
        btns[j].classList.toggle('active', active);
    }
}
</script>
