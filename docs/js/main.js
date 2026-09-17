/**
 * AI PBX Documentation & Portal Main JavaScript
 * Handles Navigation, Mobile Drawer, Code Block Copying, Interactive Screen Switcher, and Search.
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Mobile Drawer Navigation
    const mobileToggleBtn = document.getElementById('mobileToggleBtn');
    const mobileDrawer = document.getElementById('mobileDrawer');
    const mobileDrawerClose = document.getElementById('mobileDrawerClose');
    const mobileBackdrop = document.getElementById('mobileBackdrop');
    const mobileNavItems = document.querySelectorAll('.mobile-nav-item');

    function openMenu() {
        if (mobileDrawer) mobileDrawer.classList.add('open');
        if (mobileBackdrop) mobileBackdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
        if (mobileDrawer) mobileDrawer.classList.remove('open');
        if (mobileBackdrop) mobileBackdrop.classList.remove('open');
        document.body.style.overflow = '';
    }

    if (mobileToggleBtn) mobileToggleBtn.addEventListener('click', openMenu);
    if (mobileDrawerClose) mobileDrawerClose.addEventListener('click', closeMenu);
    if (mobileBackdrop) mobileBackdrop.addEventListener('click', closeMenu);
    mobileNavItems.forEach(item => item.addEventListener('click', closeMenu));

    // 2. Code Block Copy to Clipboard
    document.querySelectorAll('.docs-code-copy, .terminal-copy').forEach(btn => {
        btn.addEventListener('click', () => {
            const container = btn.closest('.docs-code-box, .terminal-container');
            if (!container) return;
            const codeEl = container.querySelector('.docs-code-body code, .terminal-body');
            if (!codeEl) return;

            // Extract text cleanly, ignoring comments or formatting if specified
            const textToCopy = codeEl.innerText.trim();
            navigator.clipboard.writeText(textToCopy).then(() => {
                const orig = btn.innerText;
                btn.innerText = 'Copied!';
                btn.style.color = '#34d399';
                setTimeout(() => {
                    btn.innerText = orig;
                    btn.style.color = '';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy text: ', err);
            });
        });
    });

    // 3. Sidebar Search / Quick Filter
    const sidebarSearch = document.getElementById('sidebarSearch');
    if (sidebarSearch) {
        sidebarSearch.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const menuLinks = document.querySelectorAll('.sidebar-menu a');
            const groups = document.querySelectorAll('.sidebar-group');

            menuLinks.forEach(link => {
                const text = link.innerText.toLowerCase();
                const match = text.includes(query);
                link.parentElement.style.display = match ? 'block' : 'none';
            });

            groups.forEach(group => {
                const visibleLinks = group.querySelectorAll('.sidebar-menu li:not([style*="display: none"])');
                group.style.display = (visibleLinks.length > 0) ? 'block' : 'none';
            });
        });
    }

    // 4. Table of Contents Scrollspy (if TOC exists)
    const tocLinks = document.querySelectorAll('.toc-list a');
    if (tocLinks.length > 0) {
        const sections = Array.from(tocLinks).map(link => {
            const targetId = link.getAttribute('href').replace('#', '');
            return document.getElementById(targetId);
        }).filter(Boolean);

        window.addEventListener('scroll', () => {
            let current = '';
            const scrollPos = window.scrollY + 120;

            sections.forEach(section => {
                if (section.offsetTop <= scrollPos) {
                    current = section.getAttribute('id');
                }
            });

            tocLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + current) {
                    link.classList.add('active');
                }
            });
        }, { passive: true });
    }
});

// 5. Interactive Screen Switcher for Mobile Apps (exported globally)
const screensData = [
    {
        img: 'img/app_dialer.jpg',
        badge: '🟢 Canlı Santral Entegrasyonu',
        badgeColor: 'rgba(16, 185, 129, 0.15)',
        badgeBorder: 'rgba(16, 185, 129, 0.3)',
        badgeText: '#34d399',
        title: 'WebRTC HD Tuş Takımı (Dialer)',
        desc: 'Dahili 19000 (Mahir) anlık çevrimiçi kayıtlı. DTLS-SRTP şifreli WebRTC Opus ses motoru ile düşük gecikmeli, kristal netliğinde kurum içi ve dış hat görüşmeleri.',
        bullets: [
            '<strong>Hızlı Arama &amp; DTMF:</strong> 0-9, *, # tuşları ile sesli yanıt (IVR) sistemlerinde anında tuşlama yapın.',
            '<strong>Canlı Durum Göstergesi:</strong> Bağlantı durumunu (Çevrimiçi, Bağlanıyor, Çevrimdışı) üst çubukta anında görün.',
            '<strong>Sıfır Çökme / Hafif Mimari:</strong> Ağır C++ kütüphaneleri yerine standart WebRTC motoru ile sadece 2.8 MB APK.'
        ]
    },
    {
        img: 'img/app_chat.jpg',
        badge: '💬 Go WebSocket Chat Hub · Grup Sohbeti',
        badgeColor: 'rgba(0, 143, 208, 0.15)',
        badgeBorder: 'rgba(0, 143, 208, 0.3)',
        badgeText: '#38bdf8',
        title: 'Bireysel & Çok Katılımcılı Grup Sohbeti',
        desc: 'Santral kullanıcıları arasında 1-e-1 ve çok kullanıcılı grup sohbeti. Go aipbx-chat WebSocket motoru ile sıfır gecikmeli mesajlaşma, filtreleme çipleri ve rol yönetimi.',
        bullets: [
            '<strong>Grup Kanalları &amp; Hızlı Oluşturma:</strong> Çoklu katılımcı seçimi, grup adı/açıklaması ve rol yönetimi (Yönetici/Üye).',
            '<strong>Filtreleme Çipleri:</strong> "Tümü", "Bireysel" ve "Gruplar" sekmeleri ile gelen kutunuzu anında filtreleyin.',
            '<strong>Yazıyor... &amp; Okundu Bildirimi:</strong> Karşı tarafın yazma durumu, mesaj teslimi ve renkli gönderici rozetleri.'
        ]
    },
    {
        img: 'img/app_contacts.jpg',
        badge: '👥 50+ Kurumsal Dahili',
        badgeColor: 'rgba(139, 92, 246, 0.15)',
        badgeBorder: 'rgba(139, 92, 246, 0.3)',
        badgeText: '#a78bfa',
        title: 'Canlı Kurumsal Rehber',
        desc: 'Santral veri tabanındaki tüm dahililer otomatik olarak telefonunuza senkronize olur. Harici numara ezberleme zorunluluğuna son.',
        bullets: [
            '<strong>Anlık Çevrimiçi Durum:</strong> Kimin masasında veya telefonda müsait olduğunu yeşil nokta ile canlı izleyin.',
            '<strong>Rol &amp; Yetki Göstergeleri:</strong> admin, read_only_admin, cc_agent gibi departman yetkileri unvan altında belirtilir.',
            '<strong>Tek Tıkla Arama:</strong> Dahili listesindeki yeşil telefon butonuna dokunarak doğrudan çağrı başlatın.'
        ]
    },
    {
        img: 'img/app_history.jpg',
        badge: '📊 Detaylı CDR & İstatistik',
        badgeColor: 'rgba(245, 158, 11, 0.15)',
        badgeBorder: 'rgba(245, 158, 11, 0.3)',
        badgeText: '#fbbf24',
        title: 'Arama Geçmişi & Süre Sayacı',
        desc: 'Toplam 330 arama, 45 cevapsız, 47 dakika konuşma gibi tüm istatistiklerinizi tek ekrandan filtreleyip analiz edin.',
        bullets: [
            '<strong>Çoklu Filtreleme:</strong> Tümü, Cevapsız, Gelen ve Giden çağrıları tek dokunuşla ayırın.',
            '<strong>Saniye Hassasiyetinde Süre:</strong> Her aramanın başlangıç saati ve net konuşma süresi listelenir.',
            '<strong>Geri Arama:</strong> Geçmiş listedeki herhangi bir kayda dokunarak anında geri arama yapın.'
        ]
    },
    {
        img: 'img/app_login.jpg',
        badge: '⚙️ Zero Config • Build 33',
        badgeColor: 'rgba(14, 165, 233, 0.15)',
        badgeBorder: 'rgba(14, 165, 233, 0.3)',
        badgeText: '#38bdf8',
        title: 'Hızlı Sunucu Bağlantısı',
        desc: 'Karmaşık SIP portları, proxy adresleri ve STUN/TURN şifreleriyle uğraşmaya gerek yok. Yalnızca santral URL adresinizi girin.',
        bullets: [
            '<strong>Otomatik Yapılandırma Keşfi:</strong> Sunucu adresinden WSS ve TLS parametreleri dinamik olarak çekilir.',
            '<strong>Dahili Sürüm Takibi:</strong> AiPBX v1.0.32 (Build 33) ve sonraki sürümlerle %100 tam uyumlu.',
            '<strong>Self-Signed ve Güvenli TLS:</strong> Kurum içi özel SSL sertifikalarıyla kesintisiz el sıkışma.'
        ]
    }
];

function switchScreen(index) {
    const data = screensData[index];
    if (!data) return;

    // Update Tab Buttons
    const buttons = document.querySelectorAll('.screen-tab-btn');
    buttons.forEach((btn, idx) => {
        if (idx === index) btn.classList.add('active');
        else btn.classList.remove('active');
    });

    // Smooth image fade
    const imgEl = document.getElementById('activePhoneImg');
    if (imgEl) {
        imgEl.style.opacity = '0.3';
        setTimeout(() => {
            imgEl.src = data.img;
            imgEl.style.opacity = '1';
        }, 120);
    }

    // Update Meta Content
    const badgeEl = document.getElementById('activeScreenBadge');
    if (badgeEl) {
        badgeEl.innerText = data.badge;
        badgeEl.style.background = data.badgeColor;
        badgeEl.style.borderColor = data.badgeBorder;
        badgeEl.style.color = data.badgeText;
    }

    const titleEl = document.getElementById('activeScreenTitle');
    if (titleEl) titleEl.innerText = data.title;

    const descEl = document.getElementById('activeScreenDesc');
    if (descEl) descEl.innerText = data.desc;

    const bulletsContainer = document.getElementById('activeScreenBullets');
    if (bulletsContainer) {
        bulletsContainer.innerHTML = data.bullets.map(b => `
            <li class="screen-meta-bullet">
                <span class="bullet-icon">✔</span>
                <span>${b}</span>
            </li>
        `).join('');
    }
}
