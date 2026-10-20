<?php
/**
 * Custom Admin UI for `/plugins/auto-dialer`.
 * Provides campaign creation, destination picker, AMD behavior selector,
 * queue free-agent pacing toggle, CSV upload/export, and real-time call results.
 */
require_once dirname(__DIR__) . '/AutoDialerService.php';

$isTr = (function_exists('getUserLanguage') && getUserLanguage() === 'tr');
$h = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$db = getDB();
$campaigns = AutoDialerService::listCampaigns();
$selectedCampId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : (!empty($campaigns[0]['id']) ? (int)$campaigns[0]['id'] : 0);
$selectedCampaign = $selectedCampId > 0 ? AutoDialerService::getCampaign($selectedCampId) : null;
$campaignNumbers = $selectedCampaign ? AutoDialerService::listNumbers($selectedCampId, 300) : [];

// Load destination options from PBX tables
$destOptions = [
    'queue'        => [],
    'ivr'          => [],
    'extension'    => [],
    'ring_group'   => [],
    'announcement' => [],
    'ai_app'       => [],
];

try {
    foreach ($db->query("SELECT id, queue_name, title FROM pbx_queues ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $q) {
        $destOptions['queue'][] = ['id' => (string)$q['id'], 'label' => ($q['title'] ?: $q['queue_name']) . ' (' . $q['queue_name'] . ')'];
    }
} catch (\Throwable $e) {
}
try {
    foreach ($db->query("SELECT id, name FROM pbx_ivrs ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ivr) {
        $destOptions['ivr'][] = ['id' => (string)$ivr['id'], 'label' => $ivr['name'] . ' (#' . $ivr['id'] . ')'];
    }
} catch (\Throwable $e) {
}
try {
    foreach ($db->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension != '' AND is_active = 1 ORDER BY extension ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
        $destOptions['extension'][] = ['id' => (string)$u['extension'], 'label' => $u['extension'] . ' — ' . $u['full_name']];
    }
} catch (\Throwable $e) {
}
try {
    foreach ($db->query("SELECT id, group_name, group_number FROM pbx_ring_groups ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rg) {
        $destOptions['ring_group'][] = ['id' => (string)$rg['id'], 'label' => $rg['group_name'] . ' (' . $rg['group_number'] . ')'];
    }
} catch (\Throwable $e) {
}
try {
    foreach ($db->query("SELECT id, name FROM pbx_announcements ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $an) {
        $destOptions['announcement'][] = ['id' => (string)$an['id'], 'label' => $an['name']];
    }
} catch (\Throwable $e) {
}
try {
    foreach ($db->query("SELECT id, name, exten FROM pbx_ai_apps ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ai) {
        $destOptions['ai_app'][] = ['id' => (string)$ai['id'], 'label' => $ai['name'] . ' (' . $ai['exten'] . ')'];
    }
} catch (\Throwable $e) {
}

$soundOptions = [];
try {
    foreach ($db->query("SELECT title, audio_file FROM pbx_sounds ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $snd) {
        $soundOptions[] = [
            'file'  => str_replace('custom/', '', (string)$snd['audio_file']),
            'title' => (string)$snd['title'],
        ];
    }
} catch (\Throwable $e) {
}

$amdArgsPreview = AsteriskSettingsService::amdDialplanArgs();
?>

<div class="alert alert-info u-mb-20" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
    <div>
        <i class="fas fa-robot"></i>
        <strong><?php echo $isTr ? 'Global AMD Parametreleri:' : 'Global AMD Parameters:'; ?></strong>
        <code>AMD(<?php echo $h($amdArgsPreview); ?>)</code>
        <span class="text-muted u-fs-12">
            (<?php echo $isTr ? 'Sistem → Santral Ayarları → AMD sekmesinden düzenlenebilir' : 'Configurable under System → PBX Settings → AMD tab'; ?>)
        </span>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="/asterisk-settings" class="btn btn-secondary btn-sm">
            <i class="fas fa-sliders"></i> <?php echo $isTr ? 'Global AMD Ayarları' : 'Global AMD Settings'; ?>
        </a>
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <input type="hidden" name="autodial_action" value="tick_now">
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-bolt"></i> <?php echo $isTr ? 'Arama Döngüsünü Şimdi Tetikle' : 'Run Dialer Cycle Now'; ?>
            </button>
        </form>
    </div>
</div>

<!-- Create New Campaign Card -->
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-plus-circle u-primary"></i>
            <?php echo $isTr ? 'Yeni Otomatik Arama Kampanyası Oluştur' : 'Create Outbound Auto-Dial Campaign'; ?>
        </div>
    </div>
    <form method="POST" style="padding: 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="autodial_action" value="create_campaign">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Kampanya Adı *' : 'Campaign Name *'; ?></label>
                <input type="text" name="name" class="form-control" placeholder="<?php echo $isTr ? 'Örn: Ekim Tahsilat / Randevu Teyit' : 'e.g. October Survey Campaign'; ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Canlı Kişi (HUMAN) Hedef Tipi *' : 'Target Destination Type (HUMAN) *'; ?></label>
                <select name="dest_type" id="ad_dest_type" class="form-control" onchange="updateAutoDialDestOptions()">
                    <option value="queue"><?php echo $isTr ? 'Çağrı Kuyruğu (Queue)' : 'Call Queue'; ?></option>
                    <option value="ivr"><?php echo $isTr ? 'IVR (Sesli Yanıt)' : 'IVR Menu'; ?></option>
                    <option value="extension"><?php echo $isTr ? 'Dahili Abone (Extension)' : 'Extension'; ?></option>
                    <option value="ring_group"><?php echo $isTr ? 'Çağrı Grubu (Ring Group)' : 'Ring Group'; ?></option>
                    <option value="announcement"><?php echo $isTr ? 'Anons (Announcement)' : 'Announcement'; ?></option>
                    <option value="ai_app"><?php echo $isTr ? 'AI Uygulaması (Sesli Bot)' : 'AI Application'; ?></option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Aktarılacak Hedef *' : 'Target Destination *'; ?></label>
                <select name="dest_id" id="ad_dest_id" class="form-control" required></select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Kampanya Caller ID (Opsiyonel)' : 'Campaign Caller ID (Optional)'; ?></label>
                <input type="text" name="caller_id" class="form-control" placeholder="<?php echo $isTr ? 'Boş = Varsayılan Caller ID' : 'Blank = Default Caller ID'; ?>">
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Maks. Eşzamanlı Çağrı' : 'Max Concurrent Calls'; ?></label>
                <input type="number" name="max_concurrent" class="form-control" value="2" min="1" max="50" required>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Tekrar Deneme Sayısı (Meşgul/Cevapsız)' : 'Max Retries (Busy/No Answer)'; ?></label>
                <input type="number" name="max_retries" class="form-control" value="2" min="0" max="10" required>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Tekrar Deneme Aralığı (Saniye)' : 'Retry Interval (Seconds)'; ?></label>
                <input type="number" name="retry_interval_sec" class="form-control" value="300" min="30" max="86400" required>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Telesekreter (MACHINE) Çıkarsa' : 'On Answering Machine (MACHINE)'; ?></label>
                <select name="amd_machine_action" id="ad_machine_action" class="form-control" onchange="toggleMachineSoundField()">
                    <option value="hangup"><?php echo $isTr ? 'Doğrudan Kapat (Hangup)' : 'Hangup Immediately'; ?></option>
                    <option value="play_sound"><?php echo $isTr ? 'Bip Bekle & Sesli Mesaj Bırak (Playback)' : 'Wait for Beep & Leave Audio Message'; ?></option>
                </select>
            </div>

            <div class="form-group" id="ad_machine_sound_wrap" style="display: none;">
                <label class="form-label"><?php echo $isTr ? 'Telesekretere Bırakılacak Ses Kaydı' : 'Voicemail Drop Sound File'; ?></label>
                <select name="amd_machine_sound" class="form-control">
                    <option value=""><?php echo $isTr ? '— Ses Dosyası Seçin —' : '— Select Sound File —'; ?></option>
                    <?php foreach ($soundOptions as $so): ?>
                        <option value="<?php echo $h($so['file']); ?>"><?php echo $h($so['title'] . ' (' . $so['file'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 24px; margin-top: 14px;">
            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                <input type="checkbox" name="amd_enabled" value="1" checked>
                <span><?php echo $isTr ? 'AMD (Telesekreter Tespiti) Aktif' : 'Enable Answering Machine Detection (AMD)'; ?></span>
            </label>

            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                <input type="checkbox" name="queue_free_agent_pacing" value="1" checked>
                <span><?php echo $isTr ? 'Kuyruk Boşta Operatör Kontrolü (Hedef Kuyruk ise sadece boşta operatör sayısı kadar arama başlat)' : 'Queue Free-Agent Pacing (Only dial when free queue agents are available)'; ?></span>
            </label>
        </div>

        <div class="form-group u-mt-12">
            <label class="form-label"><?php echo $isTr ? 'Başlangıç Numara Listesi (Her satıra: Numara, İsim, Not)' : 'Initial Phone Numbers (One per line: Number, Name, Notes)'; ?></label>
            <textarea name="numbers_bulk" class="form-control" rows="4" placeholder="05321112233, Ahmet Yılmaz, Ekim Faturası&#10;05442223344, Ayşe Demir, Randevu Teyit"></textarea>
        </div>

        <div style="margin-top: 16px; display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-plus"></i> <?php echo $isTr ? 'Kampanyayı Oluştur' : 'Create Campaign'; ?>
            </button>
        </div>
    </form>
</div>

<!-- Campaigns List Table -->
<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-bullhorn u-primary"></i>
            <?php echo $isTr ? 'Kampanyalar (`plg_autodial_campaigns`)' : 'Campaigns (`plg_autodial_campaigns`)'; ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($campaigns); ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?php echo $isTr ? 'Kampanya' : 'Campaign'; ?></th>
                    <th><?php echo $isTr ? 'Durum' : 'Status'; ?></th>
                    <th><?php echo $isTr ? 'Hedef' : 'Destination'; ?></th>
                    <th>AMD</th>
                    <th><?php echo $isTr ? 'Kuyruk Hız Kontrolü' : 'Queue Pacing'; ?></th>
                    <th><?php echo $isTr ? 'İlerleme (Toplam / Bekleyen / İnsan / Telesekreter / Hata)' : 'Progress (Total / Pending / Human / Machine / Failed)'; ?></th>
                    <th><?php echo $isTr ? 'İşlemler' : 'Actions'; ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaigns)): ?>
                    <?php echo uiTableEmptyRow(8, $isTr ? 'Henüz oluşturulmuş bir kampanya yok.' : 'No campaigns created yet.', 'fa-phone-volume'); ?>
                <?php else: ?>
                    <?php foreach ($campaigns as $c): ?>
                        <?php
                        $cid = (int)$c['id'];
                        $st = (string)$c['status'];
                        $badgeClass = $st === 'running' ? 'badge-success' : ($st === 'completed' ? 'badge-info' : 'badge-warning');
                        ?>
                        <tr <?php echo $cid === $selectedCampId ? 'style="background: var(--bg-hover, rgba(2,132,199,0.08));"' : ''; ?>>
                            <td><strong>#<?php echo $cid; ?></strong></td>
                            <td>
                                <a href="/plugins/auto-dialer?campaign_id=<?php echo $cid; ?>" style="font-weight: 600;">
                                    <?php echo $h($c['name']); ?>
                                </a>
                                <div class="text-muted u-fs-11">
                                    <?php echo $isTr ? 'Eşzamanlı:' : 'Concurrent:'; ?> <?php echo (int)$c['max_concurrent']; ?> |
                                    <?php echo $isTr ? 'Tekrar:' : 'Retries:'; ?> <?php echo (int)$c['max_retries']; ?>
                                </div>
                            </td>
                            <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $h(strtoupper($st)); ?></span></td>
                            <td><code><?php echo $h($c['dest_type'] . ':' . $c['dest_id']); ?></code></td>
                            <td>
                                <?php if (!empty($c['amd_enabled'])): ?>
                                    <span class="badge badge-info">ON (<?php echo $h($c['amd_machine_action']); ?>)</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">OFF</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($c['dest_type'] === 'queue' && !empty($c['queue_free_agent_pacing'])): ?>
                                    <span class="badge badge-success">
                                        <?php echo $isTr ? 'Aktif (Boşta: ' : 'On (Free: '; ?><?php echo AutoDialerService::countAvailableQueueAgents((string)$c['dest_id']); ?>)
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-secondary" title="Total"><?php echo (int)$c['total_numbers']; ?></span>
                                <span class="badge badge-warning" title="Pending"><?php echo (int)$c['pending_count']; ?> <?php echo $isTr ? 'bekleyen' : 'pending'; ?></span>
                                <span class="badge badge-success" title="Human"><?php echo (int)$c['human_count']; ?> HUMAN</span>
                                <span class="badge badge-info" title="Machine"><?php echo (int)$c['machine_count']; ?> MACHINE</span>
                                <span class="badge badge-danger" title="Failed"><?php echo (int)$c['failed_count']; ?> <?php echo $isTr ? 'hata' : 'failed'; ?></span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                        <input type="hidden" name="autodial_action" value="set_status">
                                        <input type="hidden" name="campaign_id" value="<?php echo $cid; ?>">
                                        <?php if ($st === 'running'): ?>
                                            <input type="hidden" name="new_status" value="paused">
                                            <button type="submit" class="btn btn-warning btn-sm" title="<?php echo $isTr ? 'Duraklat' : 'Pause'; ?>">
                                                <i class="fas fa-pause"></i>
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="running">
                                            <button type="submit" class="btn btn-success btn-sm" title="<?php echo $isTr ? 'Başlat' : 'Start'; ?>">
                                                <i class="fas fa-play"></i>
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <a href="/plugins/auto-dialer?campaign_id=<?php echo $cid; ?>" class="btn btn-secondary btn-sm" title="<?php echo $isTr ? 'Numaralar & Sonuçlar' : 'Numbers & Results'; ?>">
                                        <i class="fas fa-list-ol"></i>
                                    </a>

                                    <a href="/plugins/auto-dialer?export_csv=<?php echo $cid; ?>" class="btn btn-secondary btn-sm" title="CSV İndir">
                                        <i class="fas fa-file-csv"></i>
                                    </a>

                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                        <input type="hidden" name="autodial_action" value="reset_numbers">
                                        <input type="hidden" name="campaign_id" value="<?php echo $cid; ?>">
                                        <input type="hidden" name="only_failed" value="1">
                                        <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo $isTr ? 'Başarısızları Tekrar Sıraya Al' : 'Retry Failed Numbers'; ?>">
                                            <i class="fas fa-rotate-right"></i>
                                        </button>
                                    </form>

                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('<?php echo $isTr ? 'Kampanya ve tüm arama kayıtları silinsin mi?' : 'Delete campaign and all its numbers?'; ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                        <input type="hidden" name="autodial_action" value="delete_campaign">
                                        <input type="hidden" name="campaign_id" value="<?php echo $cid; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="<?php echo $isTr ? 'Sil' : 'Delete'; ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($selectedCampaign): ?>
<!-- Selected Campaign Numbers & Call Results (`plg_autodial_numbers`) -->
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-address-book u-primary"></i>
            <?php echo $isTr ? 'Kampanya Numaraları ve Arama Sonuçları:' : 'Campaign Numbers & Call Results:'; ?>
            <strong>#<?php echo (int)$selectedCampaign['id']; ?> — <?php echo $h($selectedCampaign['name']); ?></strong>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="/plugins/auto-dialer?export_csv=<?php echo (int)$selectedCampaign['id']; ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-download"></i> <?php echo $isTr ? 'Sonuçları CSV İndir' : 'Export Results CSV'; ?>
            </a>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" style="padding: 16px 20px; border-bottom: 1px solid var(--border-color);">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="autodial_action" value="add_numbers">
        <input type="hidden" name="campaign_id" value="<?php echo (int)$selectedCampaign['id']; ?>">

        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'Yeni Numara Ekle (Numara, İsim, Not)' : 'Add Numbers (Number, Name, Notes)'; ?></label>
                <textarea name="numbers_bulk" class="form-control" rows="2" placeholder="05321112233, Mehmet Kaya, Not"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo $isTr ? 'veya CSV Dosyası Yükle' : 'or Upload CSV File'; ?></label>
                <input type="file" name="numbers_csv" accept=".csv,.txt" class="form-control">
                <div style="margin-top: 10px; display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-upload"></i> <?php echo $isTr ? 'Numaraları Kampanyaya Ekle' : 'Add Numbers to Campaign'; ?>
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?php echo $isTr ? 'Telefon' : 'Phone'; ?></th>
                    <th><?php echo $isTr ? 'Kişi / Not' : 'Contact / Notes'; ?></th>
                    <th><?php echo $isTr ? 'Durum' : 'Status'; ?></th>
                    <th><?php echo $isTr ? 'Deneme' : 'Attempts'; ?></th>
                    <th><?php echo $isTr ? 'Çağrı Sonucu' : 'Call Result'; ?></th>
                    <th><?php echo $isTr ? 'AMD Sonucu' : 'AMD Status'; ?></th>
                    <th><?php echo $isTr ? 'AMD Nedeni' : 'AMD Cause'; ?></th>
                    <th><?php echo $isTr ? 'Süre (sn)' : 'Duration (s)'; ?></th>
                    <th><?php echo $isTr ? 'Son Deneme' : 'Last Attempt'; ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaignNumbers)): ?>
                    <?php echo uiTableEmptyRow(10, $isTr ? 'Bu kampanyada henüz numara yok.' : 'No numbers in this campaign yet.', 'fa-list'); ?>
                <?php else: ?>
                    <?php foreach ($campaignNumbers as $n): ?>
                        <?php
                        $nSt = (string)$n['status'];
                        $nBadge = $nSt === 'completed' ? 'badge-success' : ($nSt === 'failed' ? 'badge-danger' : ($nSt === 'dialing' ? 'badge-info' : 'badge-warning'));
                        $amdSt = (string)$n['amd_status'];
                        $amdBadge = $amdSt === 'HUMAN' ? 'badge-success' : ($amdSt === 'MACHINE' ? 'badge-warning' : 'badge-secondary');
                        ?>
                        <tr>
                            <td>#<?php echo (int)$n['id']; ?></td>
                            <td><strong><code><?php echo $h($n['phone_number']); ?></code></strong></td>
                            <td>
                                <?php echo $h($n['contact_name']); ?>
                                <?php if (!empty($n['notes'])): ?>
                                    <div class="text-muted u-fs-11"><?php echo $h($n['notes']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $nBadge; ?>"><?php echo $h(strtoupper($nSt)); ?></span></td>
                            <td><?php echo (int)$n['attempts']; ?></td>
                            <td><code><?php echo $h($n['call_result'] ?: '—'); ?></code></td>
                            <td>
                                <?php if ($amdSt !== ''): ?>
                                    <span class="badge <?php echo $amdBadge; ?>"><?php echo $h($amdSt); ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="u-fs-11"><code><?php echo $h($n['amd_cause'] ?: '—'); ?></code></td>
                            <td><?php echo (int)$n['duration']; ?>s</td>
                            <td class="u-fs-12"><?php echo $h($n['last_attempt_at'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
const AUTO_DIAL_DEST_OPTIONS = <?php echo json_encode($destOptions, JSON_UNESCAPED_UNICODE); ?>;

function updateAutoDialDestOptions() {
    const typeSel = document.getElementById('ad_dest_type');
    const idSel = document.getElementById('ad_dest_id');
    if (!typeSel || !idSel) return;
    const list = AUTO_DIAL_DEST_OPTIONS[typeSel.value] || [];
    idSel.innerHTML = '';
    if (list.length === 0) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = '—';
        idSel.appendChild(opt);
        return;
    }
    list.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = item.label;
        idSel.appendChild(opt);
    });
}

function toggleMachineSoundField() {
    const actSel = document.getElementById('ad_machine_action');
    const wrap = document.getElementById('ad_machine_sound_wrap');
    if (!actSel || !wrap) return;
    wrap.style.display = (actSel.value === 'play_sound') ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    updateAutoDialDestOptions();
    toggleMachineSoundField();
});
updateAutoDialDestOptions();
</script>
