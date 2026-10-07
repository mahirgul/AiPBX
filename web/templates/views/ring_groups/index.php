<?php
/**
 * Ring groups view
 */
use PBX\Destinations\DestinationRegistry;
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-users u-primary"></i> <?php echo t('ring_groups.title', 'Çalma Grupları (Ring Groups)'); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($ring_groups); ?></span>
        </div>
        <div class="u-flex-center">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('rgHelpBox')" title="<?php echo t('common.module_guide', 'Modül Rehberi'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('ring_groups', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateRgModal()" title="<?php echo t('ring_groups.new_group_btn', 'Yeni Çalma Grubu'); ?>">
                    <i class="fas fa-plus-circle"></i>
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

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 70px;"><?php echo t('ring_groups.col_ext'); ?></th>
                    <th><?php echo t('common.group_name'); ?></th>
                    <th><?php echo t('ring_groups.col_targets'); ?></th>
                    <th><?php echo t('ring_groups.col_strategy'); ?></th>
                    <th><?php echo t('ring_groups.col_time'); ?></th>
                    <th><?php echo t('ring_groups.col_recording'); ?></th>
                    <th><?php echo t('common.status'); ?></th>
                    <th class="text-right"><?php echo t('my_phone.col_actions'); ?></th>
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
                            <td><span class="badge badge-info u-fs-13"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($rg['group_number']); ?></span></td>
                            <td class="u-strong"><?php echo htmlspecialchars($rg['name']); ?></td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                    <?php foreach ($num_array as $num): ?>
                                        <?php if (strlen($num) > 6): ?>
                                            <span class="badge badge-warning" title="<?php echo t('ring_groups.external'); ?>"><i class="fas fa-globe"></i> <?php echo htmlspecialchars($num); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-primary" title="<?php echo t('ring_groups.internal'); ?>"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($num); ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($rg['ring_strategy'] === 'sequential'): ?>
                                    <span class="badge badge-secondary"><i class="fas fa-sort-numeric-down"></i> <?php echo t('common.sequential'); ?></span>
                                <?php elseif ($rg['ring_strategy'] === 'random'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-random"></i> <?php echo t('ring_groups.random_short'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fas fa-bell"></i> <?php echo t('common.all_together'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-secondary"><?php echo (int)$rg['ring_timeout']; ?> <?php echo t('common.sec_short'); ?></span></td>
                            <td>
                                <?php if ((int)$rg['record_call'] === 1): ?>
                                    <span class="badge badge-danger" title="<?php echo t('ring_groups.recorded'); ?>"><i class="fas fa-microphone"></i> <?php echo t('ring_groups.col_recording'); ?></span>
                                <?php else: ?>
                                    <span class="text-muted u-fs-11">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$rg['is_active'] === 1): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo t('common.active'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times"></i> <?php echo t('common.passive'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if (hasModulePermission('ring_groups', 'edit')): ?>
                                    <div style="display: inline-flex; gap: 4px;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditRgModal(<?php echo json_encode($rg); ?>)' title="<?php echo t('common.edit'); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('ring_groups.confirm_delete')), ENT_QUOTES); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="delete_ring_group" value="1">
                                            <input type="hidden" name="ring_group_id" value="<?php echo $rg['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>">
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

<!-- Modal: add / edit ring group -->
<div class="modal-overlay" id="rgModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="u-title" id="rgModalTitle"><i class="fas fa-users u-primary"></i> <?php echo t('ring_groups.modal_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('rgModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_ring_group" value="1">
                <input type="hidden" name="id" id="modal_rg_id" value="0">

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ring_groups.group_number'); ?></label>
                        <input type="text" name="group_number" id="modal_rg_number" class="form-control" placeholder="<?php echo t('common.eg'); ?> 7000" pattern="[0-9]{3,6}" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('common.group_name'); ?></label>
                        <input type="text" name="name" id="modal_rg_name" class="form-control" placeholder="<?php echo t('ring_groups.name_ph'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('ring_groups.numbers'); ?></label>
                    <textarea name="numbers_list" id="modal_rg_numbers" class="form-control" rows="3" placeholder="<?php echo t('common.eg'); ?> 1001, 1002, 05051234567, 02129876543" required></textarea>
                    <small class="u-hint u-fs-11">
                        <?php echo t('ring_groups.numbers_hint'); ?>
                    </small>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ring_groups.strategy'); ?></label>
                        <select name="ring_strategy" id="modal_rg_strategy" class="form-control">
                            <option value="ringall"><?php echo t('ring_groups.ringall'); ?></option>
                            <option value="sequential"><?php echo t('common.sequential_opt'); ?></option>
                            <option value="random"><?php echo t('ring_groups.random'); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('common.ring_timeout'); ?></label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="ring_timeout" id="modal_rg_timeout" class="form-control" value="30" min="5" max="300" required>
                            <span class="u-muted u-fs-12"><?php echo t('common.seconds'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ring_groups.cid_prefix'); ?></label>
                        <input type="text" name="cid_prefix" id="modal_rg_cid_prefix" class="form-control" placeholder="<?php echo t('ring_groups.cid_prefix_ph'); ?>">
                    </div>

                    <div class="form-group" style="display: flex; align-items: flex-end; padding-bottom: 8px;">
                        <label class="form-label u-check-label u-m-0">
                            <input type="checkbox" name="record_call" id="modal_rg_record" value="1" checked class="u-check">
                            <span class="u-fw-600"><?php echo t('ring_groups.record'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ring_groups.fallback'); ?></label>
                        <select name="fallback_dest_type" id="modal_rg_dest_type" class="form-control" onchange="loadRgDestOptions()">
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('common.target'); ?></label>
                        <select name="fallback_dest_id" id="modal_rg_dest_id" class="form-control">
                            <option value=""><?php echo t('chat.loading'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="modal_rg_active" value="1" checked class="u-check">
                        <span class="u-fw-600"><?php echo t('common.group_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('rgModal')", t('common.save', 'Kaydet'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/ring_groups.js'); ?>"></script>
