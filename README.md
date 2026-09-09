# 🌐 AI PBX — Açık Kaynak Kurumsal Telefon Santralı

<p align="center">
  <img src="web/assets/img/logo.png" alt="AI PBX Logo" width="120">
</p>

<p align="center">
  <strong>Modern, web tabanlı IP PBX yönetim portalı</strong><br>
  Asterisk 22 · PHP 8 · MariaDB · WebRTC · Android
</p>

<p align="center">
  <a href="#kurulum">Kurulum</a> •
  <a href="#özellikler">Özellikler</a> •
  <a href="#mimari">Mimari</a> •
  <a href="#ekran-görüntüleri">Ekran Görüntüleri</a> •
  <a href="#katkıda-bulunma">Katkıda Bulunma</a>
</p>

---

## Özellikler

### 📞 Santral Yönetimi
- **Dahili yönetimi** — PJSIP tabanlı, dual-endpoint (SIP + WebRTC)
- **Dış hat (Trunk) yönetimi** — dinamik PJSIP trunk konfigürasyonu
- **Gelen/Giden arama yönlendirme** — DID eşleme, çıkış rotaları, zaman koşulları
- **IVR (Sesli Yanıt)** — çok seviyeli menü, zaman bazlı yönlendirme
- **Kuyruk yönetimi** — çağrı kuyruğu, ajan login/logout, bekleme müziği
- **Feature kodları** — *72 yönlendirme, *60 DND, *43 intercom vb.
- **Otomatik rollback** — Asterisk reload başarısız olursa config geri alınır

### 📠 Faks Sistemi
- **Gelen/Giden faks** — T.38 ve G.711 faks desteği (res_fax + SpanDSP)
- **WYSIWYG faks editörü** — tarayıcıdan doğrudan zengin metin faks yazma
- **PDF yükleme ve gönderme**
- **Faks tekrar gönderme (retry)**
- **E-posta bildirimi** — gelen faks otomatik e-posta ile iletilir

### 📊 Çağrı Merkezi
- **Gerçek zamanlı ajan paneli** — kuyruk durumu, aktif çağrılar
- **Ajan login/logout/mola** — web arayüzünden kontrol
- **CDR raporlama** — detaylı çağrı kayıtları, filtreleme, dışa aktarma
- **Çağrı kayıt dinleme** — kayıtlı görüşmeleri web'den dinleme

### 🌐 WebRTC Yazılım Telefonu
- **Tarayıcı içi SIP telefon** — ek yazılım gerektirmez
- **TURN/STUN desteği** — NAT arkasından sorunsuz çalışma (coturn)
- **Opus + DTLS-SRTP** — yüksek kalite, şifreli ses

### 📱 Android Uygulaması
- **Native Kotlin** uygulama
- **PJSIP + WebRTC** çift motor
- **FCM push bildirim** ile gelen arama uyandırma
- **Anlık mesajlaşma (Chat)** — Go tabanlı WebSocket backend

### 🔒 Güvenlik
- **RBAC** — rol tabanlı erişim kontrolü
- **Math CAPTCHA** + brute-force kilitleme (5 hata → 15dk IP kilidi)
- **CSRF koruması** — tüm POST formlarında token
- **fail2ban entegrasyonu**
- **Firewall yönetimi** — web arayüzünden firewalld/fail2ban kontrolü
- **SIP kimlik bilgileri API ile** — sayfa kaynağına gömülmez

### 🌍 Çoklu Dil
- Türkçe 🇹🇷 ve İngilizce 🇬🇧 (1.300+ çeviri anahtarı)
- `t()` fonksiyonu ile kolay genişleme

---

## Kurulum

### Gereksinimler
- **Ubuntu 22.04 / 24.04 / 26.04 LTS** (x86_64)
- En az **2 GB RAM**, **10 GB disk**
- Root erişimi

### Hızlı Kurulum

```bash
# 1. Projeyi klonla
git clone https://github.com/mahirgul/AiPBX.git /opt/aipbx
cd /opt/aipbx

# 2. Kurulum betiğini çalıştır
sudo bash install.sh
```

Kurulum tamamlandığında:
- **Portal**: `http://<sunucu-ip>`
- **Kullanıcı**: `admin`
- **Şifre**: `admin123` (ilk girişte değiştirin!)

### Elle Kurulum

Adım adım kurulum için [INSTALL.md](INSTALL.md) dosyasına bakın.

### Kurulum Sonrası

1. `/etc/ai-pbx.env` dosyasını düzenleyin:
   - `SITE_NAME` — portal başlığı
   - `PORTAL_DOMAIN` — alan adı (TLS için)
   - `TURN_HOST` / `TURN_SECRET` — WebRTC TURN sunucusu

2. Admin şifresini değiştirin

3. İlk dahili (extension) numaranızı ekleyin

4. (Opsiyonel) Let's Encrypt TLS sertifikası:
   ```bash
   certbot --apache -d your-domain.com
   ```

---

## Mimari

```
AiPBX/
├── web/                    # PHP MVC Web Portalı
│   ├── src/
│   │   ├── controllers/    # 35 sayfa controller
│   │   ├── services/       # 24 iş mantığı servisi
│   │   ├── repositories/   # 28 veritabanı deposu
│   │   └── sync/           # 13 Asterisk config jeneratörü
│   ├── templates/views/    # 34 PHP view şablonu
│   ├── api/                # REST API katmanı
│   ├── assets/             # CSS, JS, fontlar
│   ├── lang/               # Çoklu dil dosyaları (tr/en)
│   └── db/migrations/      # Phinx veritabanı migrasyonları
│
├── android/                # Kotlin Android Uygulaması
│   └── app/src/main/
│       └── java/com/mhrgl/aipbx/
│
├── chat/                   # Go WebSocket Chat Servisi
│   ├── main.go
│   ├── hub.go              # WebSocket hub
│   ├── handlers.go         # HTTP/WS handler'lar
│   └── db.go               # Veritabanı katmanı
│
├── asterisk-config/        # Asterisk referans konfigürasyonu
│   └── pbx/                # Modüler dialplan, PJSIP, kuyruk dosyaları
│
├── db/                     # Veritabanı şeması
│   ├── schema.sql          # Tablo yapıları
│   └── seed.sql            # Temel başlangıç verileri
│
├── install.sh              # Otomatik kurulum betiği
└── README.md
```

### Teknoloji Yığını

| Katman | Teknoloji |
|--------|-----------|
| PBX | Asterisk 22 (PJSIP, res_fax, AMI, ODBC) |
| Web Backend | PHP 8.x, Katı MVC, Composer |
| Web Frontend | Vanilla JS + CSS (framework yok) |
| Veritabanı | MariaDB (Phinx migrasyonları) |
| Chat | Go + gorilla/websocket |
| Android | Kotlin, PJSIP, WebRTC, FCM |
| WebRTC | coturn TURN/STUN, DTLS-SRTP, Opus |
| Güvenlik | fail2ban, RBAC, CSRF, CAPTCHA |

---

## Lisans

Bu proje [MIT Lisansı](web/LICENSE) ile lisanslanmıştır.

---

## Katkıda Bulunma

1. Fork edin
2. Feature branch oluşturun (`git checkout -b feature/yeni-ozellik`)
3. Commit atın (`git commit -m 'Yeni özellik ekle'`)
4. Push edin (`git push origin feature/yeni-ozellik`)
5. Pull Request açın

---

## İletişim

- **Geliştirici**: Mahir Gül
- **GitHub**: [@mahirgul](https://github.com/mahirgul)
