<?php
/**
 * Boss - Secretary Groups View
 */
use PBX\Destinations\DestinationRegistry;
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-user-tie u-primary"></i> <?php echo t('boss_secretary.title', 'Şef - Sekreter Grupları'); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($groups); ?></span>
        </div>
        <div class="u-flex-center">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('bsHelpBox')" title="<?php echo t('common.module_guide', 'Modül Rehberi'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('boss_secretary', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateBsModal()" title="<?php echo t('boss_secretary.new_group_btn', 'Yeni Şef Grubu'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="bsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('boss_secretary.help_title', 'Şef - Sekreter Modülü Nasıl Çalışır?'); ?></h4>
        <p><?php echo t('boss_secretary.help_body', 'Yöneticilerin doğrudan aranarak rahatsız edilmesini engeller:'); ?></p>
        <ul>
            <li><strong><?php echo t('boss_secretary.help_intercept', 'Arama Yakalama:'); ?></strong> <?php echo t('boss_secretary.help_intercept_desc', 'Dahili veya harici bir arayan şefin numarasını tuşladığında arama otomatik olarak tanımlı sekreter(ler)e aktarılır.'); ?></li>
            <li><strong><?php echo t('boss_secretary.help_direct', 'Doğrudan Arama:'); ?></strong> <?php echo t('boss_secretary.help_direct_desc', 'Sekreterler ve VIP/Beyaz Listedeki dahililer şefi doğrudan arayabilir.'); ?></li>
            <li><strong><?php echo t('boss_secretary.help_strategy', 'Çalma Stratejisi:'); ?></strong> <?php echo t('boss_secretary.help_strategy_desc', 'Hepsi Birlikte (Aynı anda çalar, ilk açan bağlanır) veya Sırayla (Belirlenen sırayla teker teker çalar).'); ?></li>
        </ul>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 70px;">Grup #</th>
                    <th>Grup Adı</th>
                    <th>Şef (Müdür)</th>
                    <th>Sekreterler</th>
                    <th>Çalma Şekli</th>
                    <th>Zaman Aşımı</th>
                    <th>Durum</th>
                    <th class="text-right">İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($groups)): ?>
                    <?php echo uiTableEmptyRow(8, t('boss_secretary.empty', 'Henüz tanımlanmış bir şef-sekreter grubu bulunmuyor.'), 'fa-user-tie'); ?>
                <?php else: ?>
                    <?php foreach ($groups as $g): ?>
                        <?php 
                        $secs = !empty($g['secretaries_json']) ? json_decode($g['secretaries_json'], true) : [];
                        ?>
                        <tr>
                            <td><span class="badge badge-primary">Grup <?php echo (int)$g['group_number']; ?></span></td>
                            <td class="u-strong"><?php echo htmlspecialchars($g['group_name']); ?></td>
                            <td>
                                <span class="badge badge-warning u-fs-13">
                                    <i class="fas fa-crown"></i> <?php echo htmlspecialchars($g['boss_extension']); ?>
                                </span>
                                <?php if (!empty($g['boss_name'])): ?>
                                    <span style="font-size: 12px; color: var(--text-muted); margin-left: 4px;"><?php echo htmlspecialchars($g['boss_name']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                    <?php if (empty($secs)): ?>
                                        <span class="text-muted u-fs-12">Tanımlı değil</span>
                                    <?php else: ?>
                                        <?php foreach ($secs as $s_ext): ?>
                                            <span class="badge badge-info"><i class="fas fa-user"></i> <?php echo htmlspecialchars($s_ext); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($g['ring_strategy'] === 'sequential'): ?>
                                    <span class="badge badge-secondary"><i class="fas fa-sort-numeric-down"></i> Sırayla</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fas fa-bell"></i> Hepsi Birlikte</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-secondary"><?php echo (int)$g['ring_timeout']; ?> sn</span></td>
                            <td>
                                <?php if ((int)$g['is_active'] === 1): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times"></i> Pasif</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if (hasModulePermission('boss_secretary', 'edit')): ?>
                                    <div style="display: inline-flex; gap: 4px;">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditBsModal(<?php echo json_encode($g); ?>)' title="Düzenle">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="u-inline" onsubmit="return confirm('Bu grubu silmek istediğinizden emin misiniz?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="delete_group" value="1">
                                            <input type="hidden" name="group_id" value="<?php echo $g['id']; ?>">
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

<!-- Modal: add / edit boss group -->
<div class="modal-overlay" id="bsModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="u-title" id="bsModalTitle"><i class="fas fa-user-tie u-primary"></i> Şef - Sekreter Grubu</h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('bsModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_group" value="1">
                <input type="hidden" name="id" id="modal_bs_id" value="0">

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Grup Numarası</label>
                        <input type="number" name="group_number" id="modal_bs_group_number" class="form-control" value="1" min="1" max="99" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Grup Adı</label>
                        <input type="text" name="group_name" id="modal_bs_group_name" class="form-control" placeholder="ör: Genel Müdürlük Grubu" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-crown text-warning"></i> Şef Dahilisi (Müdür / Yönetici)</label>
                    <select name="boss_extension" id="modal_bs_boss" class="form-control" required>
                        <option value="">-- Şef Dahilisini Seçin --</option>
                        <?php foreach ($extensions as $ext): ?>
                            <option value="<?php echo htmlspecialchars($ext['extension']); ?>">
                                <?php echo htmlspecialchars($ext['extension']); ?> - <?php echo htmlspecialchars($ext['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="u-hint u-fs-11">Bu numara arandığında çağrı doğrudan sekreterlere aktarılır.</small>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-user-friends text-info"></i> Sekreter Dahilileri</label>
                    <div style="max-height: 150px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px; background: var(--bg-card); display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <?php foreach ($extensions as $ext): ?>
                            <label class="u-check-label-6 u-fs-12">
                                <input type="checkbox" name="secretaries[]" value="<?php echo htmlspecialchars($ext['extension']); ?>" class="bs-secretary-chk u-accent">
                                <span><?php echo htmlspecialchars($ext['extension']); ?> (<?php echo htmlspecialchars($ext['full_name']); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label">Çalma Şekli</label>
                        <select name="ring_strategy" id="modal_bs_strategy" class="form-control">
                            <option value="ringall">Hepsi Birlikte (Aynı Anda Çal)</option>
                            <option value="sequential">Sırayla (Teker Teker Çal)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Çalma Süresi (Zaman Aşımı)</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="ring_timeout" id="modal_bs_timeout" class="form-control" value="20" min="5" max="120" required>
                            <span class="u-muted u-fs-12">saniye</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">VIP / Beyaz Liste Dahilileri (Doğrudan Bağlanabilenler)</label>
                    <input type="text" name="whitelist_extensions" id="modal_bs_whitelist" class="form-control" placeholder="ör: 1005, 1006 (virgülle ayırın)">
                    <small class="u-hint u-fs-11">Bu dahililer şefi aradığında sekretere takılmadan doğrudan şef çalar.</small>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label">Sekreterler Açmazsa Hedef Tipi</label>
                        <select name="fallback_dest_type" id="modal_bs_dest_type" class="form-control" onchange="loadBsDestOptions()">
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Hedef</label>
                        <select name="fallback_dest_id" id="modal_bs_dest_id" class="form-control">
                            <option value="">Yükleniyor...</option>
                        </select>
                    </div>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="modal_bs_active" value="1" checked class="u-check">
                        <span class="u-fw-600">Grup Aktif</span>
                    </label>
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('bsModal')", t('common.save', 'Kaydet'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script>window.BOSS_SECRETARY_NEXT_NUMBER = <?php echo count($groups) + 1; ?>;</script>
<script src="<?php echo asset('/assets/js/boss_secretary.js'); ?>"></script>
