<?php
/**
 * AiPBX Admin — Control Panel & data.json Editor
 */
require_once __DIR__ . '/../includes/session.php';

if (empty($_SESSION['admin_logged_in']) ||
    (time() - ($_SESSION['admin_login_time'] ?? 0)) >= SESSION_LIFETIME) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$dataFile = __DIR__ . '/../data.json';
$currentData = [];
if (file_exists($dataFile)) {
    $currentData = json_decode(file_get_contents($dataFile), true) ?: [];
}

$pageTitle = 'AiPBX Yönetim Paneli — data.json Tablo Editörü';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0f172a;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
        }
        .admin-sidebar {
            width: 260px;
            background: #1e293b;
            border-right: 1px solid #334155;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            min-height: 100vh;
        }
        .sidebar-brand {
            padding: 24px 20px;
            border-bottom: 1px solid #334155;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar-brand img {
            width: 36px;
            height: 36px;
            border-radius: 8px;
        }
        .sidebar-brand-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #ffffff;
        }
        .sidebar-nav {
            padding: 16px 10px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .nav-tab-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
            width: 100%;
        }
        .nav-tab-btn:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }
        .nav-tab-btn.active {
            background: #0284c7;
            color: #ffffff;
        }
        .admin-content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .admin-topbar {
            padding: 18px 32px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .admin-topbar-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #ffffff;
        }
        .admin-topbar-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-save {
            background: #10b981;
            color: #ffffff;
        }
        .btn-save:hover { background: #059669; }
        .btn-view {
            background: rgba(255, 255, 255, 0.08);
            border-color: #475569;
            color: #f8fafc;
        }
        .btn-view:hover { background: rgba(255, 255, 255, 0.15); }
        .btn-logout {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border-color: #ef4444;
        }
        .btn-logout:hover { background: rgba(239, 68, 68, 0.25); }
        .admin-body {
            padding: 32px;
            max-width: 1200px;
        }
        .admin-tab-pane {
            display: none;
        }
        .admin-tab-pane.active {
            display: block;
        }
        .form-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
        }
        .form-card-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #38bdf8;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 6px;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            color: #ffffff;
            font-size: 0.9rem;
            outline: none;
        }
        .form-control:focus {
            border-color: #38bdf8;
        }
        textarea.form-control {
            min-height: 80px;
            resize: vertical;
            line-height: 1.5;
        }
        .json-editor {
            width: 100%;
            min-height: 600px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.88rem;
            background: #090d16;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 20px;
            color: #38bdf8;
            outline: none;
            line-height: 1.6;
        }
        .toast-msg {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 14px 22px;
            border-radius: 10px;
            background: #10b981;
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            display: none;
            z-index: 1000;
        }
    </style>
</head>
<body>

    <!-- Sidebar Navigation -->
    <aside class="admin-sidebar">
        <div class="sidebar-brand">
            <img src="/logo.png" alt="AiPBX">
            <div>
                <div class="sidebar-brand-title">AiPBX Admin</div>
                <div style="font-size:0.72rem; color:#94a3b8;">data.json Yönetimi</div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <button class="nav-tab-btn active" data-tab="tab-general"><i class="fa-solid fa-sliders"></i> Genel &amp; Hero</button>
            <button class="nav-tab-btn" data-tab="tab-stats"><i class="fa-solid fa-chart-simple"></i> İstatistikler</button>
            <button class="nav-tab-btn" data-tab="tab-tech"><i class="fa-solid fa-microchip"></i> Yeni Teknolojiler</button>
            <button class="nav-tab-btn" data-tab="tab-comparison"><i class="fa-solid fa-scale-balanced"></i> Karşılaştırma Matrisi</button>
            <button class="nav-tab-btn" data-tab="tab-protocols"><i class="fa-solid fa-network-wired"></i> Ağ &amp; Protokoller</button>
            <button class="nav-tab-btn" data-tab="tab-starcodes"><i class="fa-solid fa-asterisk"></i> Yıldız Kodları</button>
            <button class="nav-tab-btn" data-tab="tab-faq"><i class="fa-solid fa-circle-question"></i> SSS (FAQ)</button>
            <button class="nav-tab-btn" data-tab="tab-raw"><i class="fa-solid fa-code"></i> Ham data.json Editörü</button>
        </nav>
    </aside>

    <!-- Main Content Area -->
    <div class="admin-content">
        <div class="admin-topbar">
            <div class="admin-topbar-title" id="pageHeading">Genel &amp; Hero Parametreleri</div>
            <div class="admin-topbar-actions">
                <button type="button" class="btn-action btn-save" id="btnSaveData">
                    <i class="fa-solid fa-floppy-disk"></i> Değişiklikleri Kaydet
                </button>
                <a href="/" target="_blank" class="btn-action btn-view">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Canlı Siteyi Gör
                </a>
                <button type="button" class="btn-action btn-logout" id="btnLogout">
                    <i class="fa-solid fa-right-from-bracket"></i> Çıkış
                </button>
            </div>
        </div>

        <div class="admin-body">
            <!-- 1. GENERAL & HERO TAB -->
            <div class="admin-tab-pane active" id="tab-general">
                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-building"></i> Şirket &amp; Sürüm Bilgileri</div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Sürüm (Version)</label>
                            <input type="text" class="form-control" id="compVersion" value="<?= htmlspecialchars($currentData['company']['version'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Derleme Numarası (Build)</label>
                            <input type="text" class="form-control" id="compBuild" value="<?= htmlspecialchars($currentData['company']['build'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tek Satırlık Kurulum Komutu (Turnkey)</label>
                            <input type="text" class="form-control" id="compTurnkey" value="<?= htmlspecialchars($currentData['company']['turnkeyCommand'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">GitHub Repo URL</label>
                            <input type="text" class="form-control" id="compRepo" value="<?= htmlspecialchars($currentData['company']['repo'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-newspaper"></i> Hero Başlık ve Tanıtım Metinleri</div>
                    <div class="form-group">
                        <label class="form-label">Rozet Metni (TR)</label>
                        <input type="text" class="form-control" id="heroBadgeTR" value="<?= htmlspecialchars($currentData['hero']['badgeTR'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ana Başlık (TR)</label>
                        <input type="text" class="form-control" id="heroTitleTR" value="<?= htmlspecialchars($currentData['hero']['titleTR'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Alt Açıklama (TR)</label>
                        <textarea class="form-control" id="heroSubTR"><?= htmlspecialchars($currentData['hero']['subtitleTR'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- 2. STATS TAB -->
            <div class="admin-tab-pane" id="tab-stats">
                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-chart-line"></i> Ana Sayfa İstatistik Göstergeleri (4 Metrik)</div>
                    <div id="statsContainer"></div>
                </div>
            </div>

            <!-- 3. FLAGSHIP TECH TAB -->
            <div class="admin-tab-pane" id="tab-tech">
                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-microchip"></i> 8 Yeni Teknolojik Yetenek</div>
                    <div id="techContainer"></div>
                </div>
            </div>

            <!-- 4. COMPARISON TAB -->
            <div class="admin-tab-pane" id="tab-comparison">
                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-scale-balanced"></i> Karşılaştırma Matrisi Tablosu</div>
                    <div id="comparisonContainer"></div>
                </div>
            </div>

            <!-- 5. PROTOCOLS TAB -->
            <div class="admin-tab-pane" id="tab-protocols">
                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-network-wired"></i> Ağ Portları ve Protokoller</div>
                    <div id="protocolsContainer"></div>
                </div>
            </div>

            <!-- 6. STAR CODES TAB -->
            <div class="admin-tab-pane" id="tab-starcodes">
                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-asterisk"></i> Özel Yıldız Kodları (*90, *22, vb.)</div>
                    <div id="starcodesContainer"></div>
                </div>
            </div>

            <!-- 7. FAQ TAB -->
            <div class="admin-tab-pane" id="tab-faq">
                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-circle-question"></i> Sıkça Sorulan Sorular</div>
                    <div id="faqContainer"></div>
                </div>
            </div>

            <!-- 8. RAW JSON EDITOR TAB -->
            <div class="admin-tab-pane" id="tab-raw">
                <div class="form-card">
                    <div class="form-card-title"><i class="fa-solid fa-code"></i> Tam data.json Düzenleyici</div>
                    <p style="font-size:0.86rem; color:#94a3b8; margin-bottom:14px;">Tüm site parametrelerini doğrudan JSON sözdizimi ile düzenleyip kaydedebilirsiniz.</p>
                    <textarea class="json-editor" id="rawJsonEditor"></textarea>
                </div>
            </div>
        </div>
    </div>

    <div id="toast" class="toast-msg"></div>

    <script>
        let siteData = <?= json_encode($currentData, JSON_UNESCAPED_UNICODE) ?>;

        // CSRF Token
        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        }

        function showToast(msg, isError = false) {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.style.background = isError ? '#ef4444' : '#10b981';
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 3000);
        }

        // Tab Navigation
        const navBtns = document.querySelectorAll('.nav-tab-btn');
        const tabPanes = document.querySelectorAll('.admin-tab-pane');
        const heading = document.getElementById('pageHeading');

        navBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                navBtns.forEach(b => b.classList.remove('active'));
                tabPanes.forEach(p => p.classList.remove('active'));

                btn.classList.add('active');
                const target = btn.getAttribute('data-tab');
                document.getElementById(target).classList.add('active');
                heading.textContent = btn.textContent.trim();

                if (target === 'tab-raw') {
                    syncFormToRaw();
                }
            });
        });

        // Initialize Raw JSON Editor
        function syncFormToRaw() {
            // Update company
            siteData.company.version = document.getElementById('compVersion').value;
            siteData.company.build = document.getElementById('compBuild').value;
            siteData.company.turnkeyCommand = document.getElementById('compTurnkey').value;
            siteData.company.repo = document.getElementById('compRepo').value;

            // Update hero
            siteData.hero.badgeTR = document.getElementById('heroBadgeTR').value;
            siteData.hero.titleTR = document.getElementById('heroTitleTR').value;
            siteData.hero.subtitleTR = document.getElementById('heroSubTR').value;

            document.getElementById('rawJsonEditor').value = JSON.stringify(siteData, null, 2);
        }

        // Render Stats Tab
        function renderStats() {
            const c = document.getElementById('statsContainer');
            c.innerHTML = '';
            (siteData.stats || []).forEach((st, idx) => {
                const div = document.createElement('div');
                div.className = 'form-grid-2';
                div.style.marginBottom = '16px';
                div.style.paddingBottom = '16px';
                div.style.borderBottom = '1px solid #334155';
                div.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Değer (Stat #${idx+1})</label>
                        <input type="text" class="form-control stat-val" data-idx="${idx}" value="${st.value || ''}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Etiket (TR)</label>
                        <input type="text" class="form-control stat-lbl" data-idx="${idx}" value="${st.labelTR || ''}">
                    </div>
                `;
                c.appendChild(div);
            });
        }

        // Save Data Handler
        document.getElementById('btnSaveData').addEventListener('click', async () => {
            const activeTab = document.querySelector('.admin-tab-pane.active').id;
            let payload = siteData;

            if (activeTab === 'tab-raw') {
                try {
                    payload = JSON.parse(document.getElementById('rawJsonEditor').value);
                    siteData = payload;
                } catch (e) {
                    showToast('Hatalı JSON sözdizimi: ' + e.message, true);
                    return;
                }
            } else {
                syncFormToRaw();
                payload = siteData;
            }

            const btn = document.getElementById('btnSaveData');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Kaydediliyor...';

            try {
                const res = await fetch('../api/save_data.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify(payload)
                });
                const resJson = await res.json();
                if (resJson.success) {
                    showToast('data.json başarıyla güncellendi!');
                } else {
                    showToast(resJson.message || 'Kayıt hatası.', true);
                }
            } catch (err) {
                showToast('Sunucu bağlantı hatası: ' + err.message, true);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Değişiklikleri Kaydet';
            }
        });

        // Logout Handler
        document.getElementById('btnLogout').addEventListener('click', async () => {
            await fetch('../api/auth.php?action=logout');
            window.location.href = 'login.php';
        });

        // Initialize on load
        document.addEventListener('DOMContentLoaded', () => {
            renderStats();
            document.getElementById('rawJsonEditor').value = JSON.stringify(siteData, null, 2);
        });
    </script>
</body>
</html>
