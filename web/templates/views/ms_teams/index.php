<?php
/**
 * Microsoft Teams Entegrasyon Ekranı (Direct Routing, Kullanıcı Eşleme, Webhooks, PowerShell)
 */
$is_teams_enabled = !empty($settings['teams_enabled']) && $settings['teams_enabled'] !== '0';
$is_webhook_enabled = !empty($settings['teams_webhook_enabled']) && $settings['teams_webhook_enabled'] !== '0';
$cert = $certInfo ?? ['exists' => false, 'message' => 'Kontrol edilmedi'];
?>

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

<style>
.teams-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid var(--border-color, #e2e8f0);
    padding: 0 20px;
    background: var(--bg-card, #ffffff);
    border-radius: 8px 8px 0 0;
    overflow-x: auto;
}
.teams-tab-btn {
    padding: 14px 18px;
    border: none;
    background: transparent;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-muted, #64748b);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.teams-tab-btn:hover {
    color: #6264a7;
}
.teams-tab-btn.active {
    color: #6264a7;
    border-bottom-color: #6264a7;
}
.teams-tab-pane {
    display: none;
    padding: 24px 20px;
}
.teams-tab-pane.active {
    display: block;
}
.teams-header-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.badge-active {
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
}
.badge-inactive {
    background: rgba(148, 163, 184, 0.2);
    color: #64748b;
}
.cert-card {
    background: var(--bg-surface, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    padding: 16px;
    margin-top: 10px;
}
.code-box {
    background: #1e1e2e;
    color: #cdd6f4;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 13px;
    padding: 16px;
    border-radius: 8px;
    overflow-x: auto;
    white-space: pre;
    line-height: 1.5;
    border: 1px solid #313244;
}
.mapping-table th, .mapping-table td {
    padding: 12px 14px;
    vertical-align: middle;
}
.btn-teams {
    background: #6264a7;
    color: #ffffff;
    border: 1px solid #545794;
}
.btn-teams:hover {
    background: #50528c;
    color: #ffffff;
}
</style>

<div class="card" style="border-top: 4px solid #6264a7;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div class="card-title" style="display: flex; align-items: center; gap: 10px; font-size: 18px; font-weight: 700;">
            <i class="fab fa-microsoft" style="color: #6264a7; font-size: 22px;"></i>
            <span>Microsoft Teams Entegrasyonu</span>
            <?php if ($is_teams_enabled): ?>
                <span class="teams-header-badge badge-active"><i class="fas fa-check-circle"></i> Direct Routing Aktif</span>
            <?php else: ?>
                <span class="teams-header-badge badge-inactive"><i class="fas fa-pause-circle"></i> Direct Routing Pasif</span>
            <?php endif; ?>
            <?php if ($is_webhook_enabled): ?>
                <span class="teams-header-badge badge-active"><i class="fas fa-bell"></i> Webhook Açık</span>
            <?php endif; ?>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleTeamsHelp()" title="Rehber">
            <i class="fas fa-question-circle"></i> Yardım & Rehber
        </button>
    </div>

    <!-- Rehber Kutusu -->
    <div class="module-help-box" id="teamsHelpBox" style="margin: 16px 20px 0 20px; display: none; padding: 16px; background: rgba(98, 100, 167, 0.08); border-left: 4px solid #6264a7; border-radius: 4px;">
        <h4 style="color: #464775; margin-top: 0;"><i class="fab fa-microsoft"></i> Microsoft Teams Entegrasyon Rehberi</h4>
        <p style="font-size: 13px; line-height: 1.6; margin-bottom: 8px;">
            AiPBX, Microsoft Teams ile iki farklı seviyede haberleşebilir:
        </p>
        <ul style="font-size: 13px; line-height: 1.6; margin-bottom: 8px;">
            <li><strong>1. Direct Routing (SBC / SIP Bağlantısı):</strong> Teams kullanıcılarının masaüstü/mobil Teams uygulamasındaki numaratörden (Dialpad) dahili ve harici aramalar yapmasını/karşılamasını sağlar. TLS (Port 5061), SRTP ve geçerli bir genel SSL sertifikası (Let's Encrypt vb.) gerektirir.</li>
            <li><strong>2. Webhook & Kanal Bildirimleri:</strong> Cevapsız çağrılar, sesli mesajlar, gelen fakslar ve çağrı merkezi alarmlarını Teams kanalına anlık interaktif kart (Adaptive Card) olarak gönderir. Ek lisans gerektirmez.</li>
            <li><strong>3. Otomatik PowerShell Oluşturucu:</strong> "PowerShell Rehberi" sekmesinden santral ayarlarınıza göre otomatik hazırlanmış komutları tek tıkla kopyalayıp Microsoft 365 yönetici terminalinde çalıştırabilirsiniz.</li>
        </ul>
    </div>

    <!-- Sekmeler (Tabs) -->
    <div class="teams-tabs">
        <button type="button" class="teams-tab-btn <?php echo $active_tab === 'direct_routing' ? 'active' : ''; ?>" onclick="openTeamsTab('direct_routing')">
            <i class="fas fa-network-wired"></i> <span>Direct Routing (SBC)</span>
        </button>
        <button type="button" class="teams-tab-btn <?php echo $active_tab === 'users' ? 'active' : ''; ?>" onclick="openTeamsTab('users')">
            <i class="fas fa-users-cog"></i> <span>Kullanıcı Eşleştirme (<?php echo count($mappings); ?>)</span>
        </button>
        <button type="button" class="teams-tab-btn <?php echo $active_tab === 'webhooks' ? 'active' : ''; ?>" onclick="openTeamsTab('webhooks')">
            <i class="fas fa-paper-plane"></i> <span>Webhook & Bildirimler</span>
        </button>
        <button type="button" class="teams-tab-btn <?php echo $active_tab === 'powershell' ? 'active' : ''; ?>" onclick="openTeamsTab('powershell')">
            <i class="fas fa-terminal"></i> <span>M365 PowerShell Rehberi</span>
        </button>
    </div>

    <!-- ========================================== -->
    <!-- SEKME 1: Direct Routing (SBC / SIP)       -->
    <!-- ========================================== -->
    <div id="tab-direct_routing" class="teams-tab-pane <?php echo $active_tab === 'direct_routing' ? 'active' : ''; ?>">
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
            <input type="hidden" name="save_direct_routing" value="1">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;">Direct Routing Durumu</label>
                    <select name="teams_enabled" class="form-control" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                        <option value="1" <?php echo ($settings['teams_enabled'] === '1') ? 'selected' : ''; ?>>Aktif (Microsoft Teams SIP Bağlantısı Açık)</option>
                        <option value="0" <?php echo ($settings['teams_enabled'] === '0') ? 'selected' : ''; ?>>Devre Dışı (Pasif)</option>
                    </select>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">Aktif olduğunda Asterisk TLS 5061 portu Microsoft PSTN Hub sunucularına yanıt verir.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;">SBC FQDN / Domain Adı <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="teams_domain" class="form-control" value="<?php echo htmlspecialchars($settings['teams_domain']); ?>" placeholder="örn: sbc.sirketiniz.com" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">Microsoft 365 Domain yönetiminde doğrulanmış ve bu sunucunun statik IP'sine yönlendirilmiş FQDN.</small>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;">SIP TLS Portu</label>
                    <input type="number" name="teams_sip_port" class="form-control" value="<?php echo htmlspecialchars($settings['teams_sip_port'] ?: '5061'); ?>" min="1" max="65535" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">Microsoft Teams Direct Routing standardı port <strong>5061</strong>'dir.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight: 600;">SBC Tanımlayıcı Adı (İsteğe Bağlı)</label>
                    <input type="text" name="teams_sbc_name" class="form-control" value="<?php echo htmlspecialchars($settings['teams_sbc_name']); ?>" placeholder="AiPBX-SBC-Gateway" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">Microsoft Teams yönetim merkezinde görünecek açıklayıcı isim.</small>
                </div>
            </div>

            <!-- Sertifika Durumu ve Yolları -->
            <div style="background: var(--bg-surface, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 18px; margin-bottom: 24px;">
                <h4 style="margin-top: 0; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-lock" style="color: var(--success);"></i> SIP TLS & Güvenlik Sertifikası
                </h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
                    <div class="form-group">
                        <label class="form-label" style="font-size: 13px; font-weight: 600;">TLS Sertifika Dosya Yolu (.crt / .pem)</label>
                        <input type="text" name="teams_tls_cert_path" class="form-control" value="<?php echo htmlspecialchars($settings['teams_tls_cert_path']); ?>" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-size: 13px; font-weight: 600;">Özel Anahtar Dosya Yolu (.key)</label>
                        <input type="text" name="teams_tls_key_path" class="form-control" value="<?php echo htmlspecialchars($settings['teams_tls_key_path']); ?>" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    </div>
                </div>

                <!-- Sertifika İnceleme Rozeti -->
                <div class="cert-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <span class="badge badge-<?php echo htmlspecialchars($cert['color'] ?? 'secondary'); ?>" style="font-size: 12px; padding: 4px 8px;">
                                <?php echo htmlspecialchars($cert['message'] ?? 'Bilinmiyor'); ?>
                            </span>
                            <?php if (!empty($cert['cn'])): ?>
                                <strong style="margin-left: 8px; font-size: 13px;">CN: <?php echo htmlspecialchars($cert['cn']); ?></strong>
                                <span style="color: var(--text-muted); font-size: 12px;">(Sağlayıcı: <?php echo htmlspecialchars($cert['issuer']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($cert['valid_to'])): ?>
                            <div style="font-size: 12px; color: var(--text-muted);">
                                Son Geçerlilik: <strong><?php echo htmlspecialchars($cert['valid_to']); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Microsoft PSTN Hub Proxy Bilgi Kartı -->
            <div style="background: rgba(98, 100, 167, 0.05); border: 1px solid rgba(98, 100, 167, 0.2); border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                <h5 style="margin: 0 0 10px 0; color: #464775; font-size: 14px; font-weight: 700;">
                    <i class="fas fa-globe"></i> Microsoft Teams Global SIP Proxy Adresleri
                </h5>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px;">
                    Sunucunuzun güvenlik duvarında (Firewall) bu Microsoft IP bloklarına SIP (5061/TCP) ve Medya (10000-20000/UDP) erişimi açık olmalıdır:
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px;">
                    <code style="background: #ffffff; padding: 4px 8px; border-radius: 4px; border: 1px solid #e2e8f0;">sip.pstnhub.microsoft.com:5061</code>
                    <code style="background: #ffffff; padding: 4px 8px; border-radius: 4px; border: 1px solid #e2e8f0;">sip2.pstnhub.microsoft.com:5061</code>
                    <code style="background: #ffffff; padding: 4px 8px; border-radius: 4px; border: 1px solid #e2e8f0;">sip3.pstnhub.microsoft.com:5061</code>
                </div>
            </div>

            <?php if ($can_edit): ?>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-teams" style="padding: 10px 24px; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-save"></i> Direct Routing Ayarlarını Kaydet
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ========================================== -->
    <!-- SEKME 2: Kullanıcı Eşleştirme             -->
    <!-- ========================================== -->
    <div id="tab-users" class="teams-tab-pane <?php echo $active_tab === 'users' ? 'active' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h4 style="margin: 0; font-size: 16px; font-weight: 700;">Dahili & Microsoft Teams Kullanıcı Eşleştirmeleri</h4>
                <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted);">
                    Hangi AiPBX dahili abonesinin hangi Microsoft 365 Teams kullanıcısı ile konuşacağını buradan yönetin.
                </p>
            </div>
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-teams btn-sm" onclick="openMappingModal()">
                    <i class="fas fa-plus"></i> Yeni Eşleştirme Ekle
                </button>
            <?php endif; ?>
        </div>

        <div class="table-responsive" style="border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px;">
            <table class="table mapping-table" style="margin-bottom: 0;">
                <thead style="background: var(--bg-surface, #f8fafc);">
                    <tr>
                        <th style="width: 140px;">Dahili No</th>
                        <th>Kullanıcı Adı</th>
                        <th>Teams UPN / E-posta</th>
                        <th>Harici Telefon (E.164)</th>
                        <th style="text-align: center; width: 100px;">Direct Route</th>
                        <th>Notlar</th>
                        <?php if ($can_edit || $can_delete): ?>
                            <th style="text-align: right; width: 120px;">İşlemler</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mappings)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                <i class="fas fa-user-slash" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                                Henüz hiçbir kullanıcı eşleştirmesi yapılmamış. "Yeni Eşleştirme Ekle" butonuna basarak ilk aboneyi bağlayabilirsiniz.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mappings as $m): ?>
                            <tr id="mapping-row-<?php echo $m['id']; ?>">
                                <td>
                                    <span style="font-family: monospace; font-size: 14px; font-weight: 700; color: #6264a7;">
                                        <i class="fas fa-phone-alt" style="font-size: 11px;"></i> <?php echo htmlspecialchars($m['extension']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($m['full_name'] ?: '-'); ?></strong>
                                </td>
                                <td>
                                    <i class="fab fa-microsoft" style="color: #6264a7; margin-right: 4px;"></i>
                                    <code><?php echo htmlspecialchars($m['teams_upn']); ?></code>
                                </td>
                                <td>
                                    <?php echo !empty($m['phone_number']) ? htmlspecialchars($m['phone_number']) : '<span style="color: var(--text-muted);">-</span>'; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (!empty($m['direct_routing_enabled'])): ?>
                                        <span class="badge badge-success" style="font-size: 11px;">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="font-size: 11px;">Pasif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--text-muted); font-size: 13px;">
                                    <?php echo htmlspecialchars($m['notes'] ?: '-'); ?>
                                </td>
                                <?php if ($can_edit || $can_delete): ?>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <?php if ($can_edit): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='editMapping(<?php echo json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="Düzenle">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteMapping(<?php echo (int)$m['id']; ?>, '<?php echo htmlspecialchars($m['extension'], ENT_QUOTES); ?>')" title="Sil">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- SEKME 3: Webhook & Bildirimler            -->
    <!-- ========================================== -->
    <div id="tab-webhooks" class="teams-tab-pane <?php echo $active_tab === 'webhooks' ? 'active' : ''; ?>">
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
            <input type="hidden" name="save_webhook_settings" value="1">

            <div style="background: var(--bg-surface, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <h4 style="margin-top: 0; font-size: 15px; font-weight: 700; color: #464775; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-plug"></i> Teams Gelen Webhook (Incoming Webhook) Bağlantısı
                </h4>
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                    Microsoft Teams'de ilgili kanalın ayarlarından (Bağlayıcılar / Connectors) <strong>"Gelen Web kancası (Incoming Webhook)"</strong> oluşturup URL adresini buraya yapıştırın.
                </p>

                <div style="display: grid; grid-template-columns: 200px 1fr; gap: 16px; align-items: start;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 600;">Webhook Durumu</label>
                        <select name="teams_webhook_enabled" id="webhookEnabledSelect" class="form-control" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                            <option value="1" <?php echo ($settings['teams_webhook_enabled'] === '1') ? 'selected' : ''; ?>>Aktif (Bildirimler Gönderilsin)</option>
                            <option value="0" <?php echo ($settings['teams_webhook_enabled'] === '0') ? 'selected' : ''; ?>>Devre Dışı</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="font-weight: 600;">Teams Webhook URL Adresi</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="url" name="teams_webhook_url" id="teamsWebhookUrlInput" class="form-control" value="<?php echo htmlspecialchars($settings['teams_webhook_url']); ?>" placeholder="https://sirket.webhook.office.com/webhookb2/..." <?php echo !$can_edit ? 'disabled' : ''; ?>>
                            <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-outline-secondary" id="btnTestWebhook" onclick="testTeamsWebhook()" style="white-space: nowrap;">
                                    <i class="fas fa-paper-plane"></i> Test Et
                                </button>
                            <?php endif; ?>
                        </div>
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;">Teams kanalında üretilen güvenli URL adresi.</small>
                    </div>
                </div>

                <div id="webhookTestResult" style="display: none; margin-top: 12px;"></div>
            </div>

            <!-- Bildirim Olayları (Event Toggles) -->
            <div style="background: #ffffff; border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <h4 style="margin-top: 0; font-size: 15px; font-weight: 700; margin-bottom: 16px;">
                    <i class="fas fa-bell" style="color: var(--primary);"></i> Teams Kanalına İletilecek Bildirim Olayları
                </h4>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_missed_calls" value="1" <?php echo !empty($settings['teams_notify_missed_calls']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong>Cevapsız Çağrı Bildirimleri</strong>
                            <div style="font-size: 12px; color: var(--text-muted);">Müşteri çağrıyı yanıtlamadan kapattığında arayan numara ve kuyruk bilgisi iletilir.</div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_voicemail" value="1" <?php echo !empty($settings['teams_notify_voicemail']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong>Sesli Mesaj (Voicemail) Bildirimleri</strong>
                            <div style="font-size: 12px; color: var(--text-muted);">Abonenin sesli mesaj kutusuna yeni bir mesaj bırakıldığında kanala haber verilir.</div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_queue_alerts" value="1" <?php echo !empty($settings['teams_notify_queue_alerts']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong>Kuyruk & Çağrı Merkezi Alarmları</strong>
                            <div style="font-size: 12px; color: var(--text-muted);">Bekleyen çağrı sayısı veya bekleme süresi kritik eşiği aştığında uyarı kartı düşer.</div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_fax" value="1" <?php echo !empty($settings['teams_notify_fax']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong>Gelen Dijital Faks Bildirimleri</strong>
                            <div style="font-size: 12px; color: var(--text-muted);">Yeni bir faks alındığında gönderen ve sayfa sayısı bilgisi Teams'e gönderilir.</div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_cdr_summary" value="1" <?php echo !empty($settings['teams_notify_cdr_summary']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong>Günlük CDR Çağrı Özeti</strong>
                            <div style="font-size: 12px; color: var(--text-muted);">Günün sonunda toplam arama, cevaplanan ve kaçan çağrı istatistik kartı atılır.</div>
                        </div>
                    </label>
                </div>
            </div>

            <?php if ($can_edit): ?>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-teams" style="padding: 10px 24px; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-save"></i> Webhook Ayarlarını Kaydet
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ========================================== -->
    <!-- SEKME 4: M365 PowerShell Rehberi          -->
    <!-- ========================================== -->
    <div id="tab-powershell" class="teams-tab-pane <?php echo $active_tab === 'powershell' ? 'active' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h4 style="margin: 0; font-size: 16px; font-weight: 700;">Microsoft 365 Teams PowerShell Yapılandırma Scripti</h4>
                <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted);">
                    AiPBX santralinizdeki domain ve kullanıcı eşleştirmelerine göre dinamik oluşturulmuş tam komut seti.
                </p>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyPowerShellScript()">
                    <i class="fas fa-copy"></i> Panoya Kopyala
                </button>
                <a href="/ms-teams?action=download_powershell" class="btn btn-sm btn-teams">
                    <i class="fas fa-download"></i> Scripti İndir (.ps1)
                </a>
            </div>
        </div>

        <div class="code-box" id="powerShellCodeBlock"><?php echo htmlspecialchars($powerShellScript); ?></div>

        <div style="margin-top: 20px; background: var(--bg-surface, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 18px;">
            <h5 style="margin-top: 0; font-size: 14px; font-weight: 700;">
                <i class="fas fa-info-circle" style="color: var(--primary);"></i> PowerShell İle Kurulum Adımları
            </h5>
            <ol style="font-size: 13px; line-height: 1.7; margin-bottom: 0; padding-left: 20px;">
                <li>Windows bilgisayarınızda PowerShell'i <strong>Yönetici Olarak (Run as Administrator)</strong> açın.</li>
                <li>Yukarıdaki scripti indirin veya panoya kopyalayıp çalıştırın.</li>
                <li>Gelen tarayıcı penceresinde <strong>Microsoft 365 Global / Teams Yöneticisi</strong> hesabınızla oturum açın.</li>
                <li>Script tamamlandığında Microsoft Teams Direct Routing bağlantınız devreye girecektir (Microsoft sunucularında yayılması ~15-30 dakika sürebilir).</li>
            </ol>
        </div>
    </div>
</div>

<!-- ========================================== -->
    <!-- KULLANICI EŞLEŞTİRME MODALI (Modal)       -->
<!-- ========================================== -->
<div class="modal fade" id="mappingModal" tabindex="-1" style="display: none; background: rgba(0,0,0,0.5); position: fixed; inset: 0; z-index: 9999; overflow-y: auto;">
    <div style="max-width: 540px; margin: 60px auto; background: var(--bg-card, #ffffff); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden;">
        <div style="padding: 16px 20px; background: #6264a7; color: #ffffff; display: flex; justify-content: space-between; align-items: center;">
            <h5 style="margin: 0; font-weight: 700; font-size: 16px;" id="mappingModalTitle">
                <i class="fas fa-user-plus"></i> Yeni Teams Eşleştirmesi
            </h5>
            <button type="button" onclick="closeMappingModal()" style="background: transparent; border: none; color: #ffffff; font-size: 20px; cursor: pointer;">&times;</button>
        </div>

        <form id="mappingForm" onsubmit="submitMappingForm(event)" style="padding: 20px;">
            <input type="hidden" name="id" id="mapId" value="">
            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 600;">AiPBX Dahili Numarası <span style="color: var(--danger);">*</span></label>
                <select name="extension" id="mapExtension" class="form-control" required>
                    <option value="">-- Dahili Seçiniz --</option>
                    <?php foreach ($extensions as $ext): ?>
                        <option value="<?php echo htmlspecialchars($ext['extension']); ?>">
                            <?php echo htmlspecialchars($ext['extension'] . ' - ' . $ext['full_name'] . ($ext['email'] ? ' (' . $ext['email'] . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 600;">Microsoft Teams Kullanıcı Adı (UPN / E-posta) <span style="color: var(--danger);">*</span></label>
                <input type="email" name="teams_upn" id="mapTeamsUpn" class="form-control" placeholder="örn: ahmet@sirketiniz.com" required>
                <small style="color: var(--text-muted); font-size: 12px;">Kullanıcının Microsoft 365 oturum açma e-posta adresi.</small>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 600;">E.164 Telefon Numarası (Opsiyonel)</label>
                <input type="text" name="phone_number" id="mapPhoneNumber" class="form-control" placeholder="örn: +902129990011 veya +101">
                <small style="color: var(--text-muted); font-size: 12px;">Teams numaratöründe görünecek DID veya E.164 formatında numara.</small>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label" style="font-weight: 600;">Direct Routing Durumu</label>
                <select name="direct_routing_enabled" id="mapDirectRouting" class="form-control">
                    <option value="1">Aktif (Sesli Arama Açık)</option>
                    <option value="0">Pasif</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label class="form-label" style="font-weight: 600;">Açıklama / Notlar</label>
                <input type="text" name="notes" id="mapNotes" class="form-control" placeholder="Örn: Satış Departmanı Yöneticisi">
            </div>

            <div id="modalAlert" style="display: none; margin-bottom: 14px;"></div>

            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline-secondary" onclick="closeMappingModal()">İptal</button>
                <button type="submit" class="btn btn-teams" id="btnSaveMapping">
                    <i class="fas fa-save"></i> Kaydet
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openTeamsTab(tabName) {
    document.querySelectorAll('.teams-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.teams-tab-pane').forEach(pane => pane.classList.remove('active'));

    const activeBtn = document.querySelector(`.teams-tab-btn[onclick*="${tabName}"]`);
    if (activeBtn) activeBtn.classList.add('active');

    const activePane = document.getElementById(`tab-${tabName}`);
    if (activePane) activePane.classList.add('active');

    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);
}

function toggleTeamsHelp() {
    const box = document.getElementById('teamsHelpBox');
    if (box) {
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
    }
}

function openMappingModal() {
    document.getElementById('mappingForm').reset();
    document.getElementById('mapId').value = '';
    document.getElementById('mappingModalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Yeni Teams Eşleştirmesi';
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('mappingModal').style.display = 'block';
}

function editMapping(item) {
    document.getElementById('mapId').value = item.id || '';
    document.getElementById('mapExtension').value = item.extension || '';
    document.getElementById('mapTeamsUpn').value = item.teams_upn || '';
    document.getElementById('mapPhoneNumber').value = item.phone_number || '';
    document.getElementById('mapDirectRouting').value = item.direct_routing_enabled ? '1' : '0';
    document.getElementById('mapNotes').value = item.notes || '';
    document.getElementById('mappingModalTitle').innerHTML = '<i class="fas fa-user-edit"></i> Eşleştirmeyi Düzenle (' + item.extension + ')';
    document.getElementById('modalAlert').style.display = 'none';
    document.getElementById('mappingModal').style.display = 'block';
}

function closeMappingModal() {
    document.getElementById('mappingModal').style.display = 'none';
}

function submitMappingForm(e) {
    e.preventDefault();
    const form = document.getElementById('mappingForm');
    const formData = new FormData(form);
    const alertBox = document.getElementById('modalAlert');
    const btn = document.getElementById('btnSaveMapping');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Kaydediliyor...';

    fetch('/ms-teams?action=save_mapping', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Kaydet';
        if (data.success) {
            window.location.href = '/ms-teams?tab=users';
        } else {
            alertBox.className = 'alert alert-danger';
            alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || data.message || 'Hata oluştu.');
            alertBox.style.display = 'block';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Kaydet';
        alertBox.className = 'alert alert-danger';
        alertBox.innerHTML = '<i class="fas fa-exclamation-circle"></i> Sunucuyla iletişim hatası: ' + err;
        alertBox.style.display = 'block';
    });
}

function deleteMapping(id, ext) {
    if (!confirm(ext + ' numaralı dahili eşleştirmesini silmek istediğinizden emin misiniz?')) {
        return;
    }

    const formData = new FormData();
    formData.append('id', id);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('/ms-teams?action=delete_mapping', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('mapping-row-' + id);
            if (row) row.remove();
        } else {
            alert(data.message || 'Silme işlemi başarısız.');
        }
    })
    .catch(err => alert('Silme hatası: ' + err));
}

function testTeamsWebhook() {
    const url = document.getElementById('teamsWebhookUrlInput').value.trim();
    const resBox = document.getElementById('webhookTestResult');
    const btn = document.getElementById('btnTestWebhook');

    if (!url) {
        alert('Lütfen test edilecek Webhook URL adresini giriniz.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
    resBox.style.display = 'none';

    const formData = new FormData();
    formData.append('webhook_url', url);
    formData.append('csrf_token', window.CSRF_TOKEN || '');

    fetch('/ms-teams?action=test_webhook', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Test Et';
        resBox.style.display = 'block';
        if (data.success) {
            resBox.className = 'alert alert-success';
            resBox.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message;
        } else {
            resBox.className = 'alert alert-danger';
            resBox.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + (data.error || data.message || 'Test başarısız.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Test Et';
        resBox.style.display = 'block';
        resBox.className = 'alert alert-danger';
        resBox.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Bağlantı hatası: ' + err;
    });
}

function copyPowerShellScript() {
    const code = document.getElementById('powerShellCodeBlock').innerText;
    navigator.clipboard.writeText(code).then(() => {
        alert('PowerShell komutları panoya kopyalandı!');
    }).catch(err => {
        alert('Kopyalama başarısız, lütfen elle seçip kopyalayınız: ' + err);
    });
}
</script>
