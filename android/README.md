# AI PBX Android Telefon Uygulaması

Bu proje, kurum içi **Asterisk Web PBX** santral sistemiyle tam entegre çalışan, **Google Cloud / Firebase bağımsız**, güvenli ve kesintisiz Android mobil softphone uygulamasıdır.

---

## 🌟 Öne Çıkan Özellikler

* 🔒 **%100 Bağımsız ve Güvenli (Zero Cloud Dependency):**
  * Hiçbir Google Cloud, Firebase veya harici 3. parti bulut servisi kullanılmaz.
  * Tüm sinyalleşme ve ses akışı doğrudan kurumunuzun sunucusu üzerinden şifreli (**WSS / TLS / TURNS**) olarak gerçekleşir.
* 🌐 **Dinamik Sunucu URL Yapılandırması:**
  * Uygulama ilk açılışta sunucu adresini ister.
  * Farklı santral kurulumları ve domain/IP adresleri için kolayca değiştirilebilir.
  * Sunucu erişilebilirliği otomatik olarak test edilir (`GET /api/mobile/ping.php`).
* 🔑 **Kolay Giriş ve Otomatik Yapılandırma:**
  * Santral kullanıcı adı / dahili numarası ve web şifresiyle tek adımda giriş.
  * Sunucudan dinamik WebRTC/SIP kimlik bilgileri, coturn TURNS şifreleri ve dahili ayarları otomatik çekilir.
* 📱 **Ekran Kapalıyken Kesintisiz Bağlantı (Background Resilience):**
  * **Foreground Service (Ön Plan Servisi):** Android Doze Mode veya agresif pil tasarrufu tarafından uygulamanın kapatılmasını önler.
  * **WakeLock & WifiLock:** Ekran kapalıyken işlemci ve Wi-Fi bağlantısının uykuya dalmasını engeller.
  * **Otomatik Başlatma (BootReceiver):** Telefon yeniden başlatıldığında servis arka planda otomatik olarak ayağa kalkar.
  * **Kilit Ekranı Uyandırma:** Gelen aramalarda telefon ekranı anında uyanır (`TurnScreenOn` / `ShowWhenLocked`) ve tam ekran çağrı arayüzü belirir.
* 📞 **Gelişmiş Telefon Özellikleri:**
  * Modern tuş takımı (0-9, *, #) ve canlı arama alanı.
  * Görüşme ekranı: Mikrofon susturma (Mute), Hoparlör (Speakerphone), Çağrıyı Bekletme (Hold) ve Görüşme Sayacı.
  * Yakınlık Sensörü (Proximity Sensor): Telefon kulağa götürüldüğünde ekranı otomatik kapatarak yanlış dokunmaları önler.

---

## 🚀 Kurulum ve Telefona Yükleme

### 1. Hazır APK Dosyası
Proje kök dizininde hazır derlenmiş APK dosyası bulunmaktadır:
* **[`ai-pbx-phone.apk`](file:///Z:/rustProjects/androidPhone/ai-pbx-phone.apk)** (~7.2 MB)

### 2. Telefona Yükleme Yöntemleri
1. **USB ile Doğrudan (En Hızlı):**
   * Telefonunuzu bilgisayara USB kablosuyla bağlayın (ve Geliştirici Seçenekleri > USB Hata Ayıklama açık olsun).
   * Terminalden tek komutla yükleyin:
     ```cmd
     adb install -r ai-pbx-phone.apk
     ```
2. **Dosya Transferi ile:**
   * `ai-pbx-phone.apk` dosyasını telefonunuza atın (Bluetooth, WhatsApp Kendine Mesaj, Telegram veya USB Dosya Aktarımı).
   * Telefonun Dosyalar uygulamasından APK'ya dokunup **"Yükle"** deyin (Gerekirse *"Bilinmeyen kaynaklardan yüklemeye izin ver"* seçeneğini onaylayın).

---

## 🛠️ Yeniden Derleme (Rebuild)

Kodda bir değişiklik yaptığınızda yeni bir APK üretmek için:
* Kök dizindeki **`build.bat`** dosyasına çift tıklamanız yeterlidir.
* Veya komut satırından:
  ```powershell
  gradle --no-daemon assembleDebug
  ```

---

## 📂 Proje Mimarisi

```
Z:\rustProjects\androidPhone\
├── ai-pbx-phone.apk                # Hazır kurulabilir Android APK dosyası
├── build.bat                       # Tek tıkla APK derleme betiği
├── app/
│   ├── src/main/
│   │   ├── AndroidManifest.xml     # VoIP izinleri, servisler ve ekran tanımları
│   │   ├── assets/
│   │   │   ├── jssip.min.js        # PBX sunucusundaki resmi JsSIP kütüphanesi
│   │   │   └── phone_engine.html   # Headless WebRTC/SIP arka plan motoru
│   │   ├── java/com/mhrgl/aipbx/
│   │   │   ├── data/
│   │   │   │   ├── ApiClient.kt    # Sunucu Ping ve Giriş REST API istemcisi
│   │   │   │   └── AppPreferences.kt # Sunucu URL ve kimlik yerel saklayıcısı
│   │   │   ├── engine/
│   │   │   │   └── SipWebRtcEngine.kt # JsSIP WebRTC çağrı köprüsü
│   │   │   ├── model/
│   │   │   │   └── Models.kt       # Veri modelleri ve çağrı durumları
│   │   │   ├── service/
│   │   │   │   ├── BootReceiver.kt # Telefon açıldığında servisi başlatıcı
│   │   │   │   └── PbxForegroundService.kt # Arka plan canlı tutma ve bildirim servisi
│   │   │   └── ui/
│   │   │       ├── ServerSetupActivity.kt # Ekran 1: Dinamik Sunucu URL Ayarı
│   │   │       ├── LoginActivity.kt       # Ekran 2: Kullanıcı Giriş Ekranı
│   │   │       ├── DialerActivity.kt      # Ekran 3: Tuş Takımı & Arama Ekranı
│   │   │       ├── CallActivity.kt        # Ekran 4: Aktif Görüşme Ekranı
│   │   │       └── IncomingCallActivity.kt # Ekran 5: Kilit Ekranı Gelen Çağrı
│   │   └── res/                    # Tasarımlar, renkler, sesler ve vektör ikonlar
```

---

## 🌐 Sunucu Entegrasyonu (`10.8.0.10`)

Sunucu üzerinde `/var/www/html/api/mobile/` dizinine eklenen uç noktalar:
1. `GET /api/mobile/ping.php`: Sunucu adresi doğrulama ve sağlık kontrolü.
2. `POST /api/mobile/login.php`: Güvenli kullanıcı doğrulaması ve dinamik SIP/WebRTC + TURNS kimlik üretimi.
