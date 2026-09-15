# AI PBX Android Kurumsal İletişim Uygulaması

> **Sürüm**: v1.0.32 (Build 33)  
> **Paket Adı**: `com.mhrgl.AiPBX`  
> **Hedef Android Sürümü**: Android 8.0 (API 26) – Android 16 (API 36)  
> **Yayın Durumu**: Google Play Console (Kapalı Test / Closed Testing Track) & Doğrudan İmzalı APK  

Bu proje, kurum içi **Asterisk 22 Web PBX** santral sistemiyle tam entegre çalışan, **harici bulut bağımlılığı olmayan**, güvenli, WebRTC tabanlı ve çok fonksiyonlu yerel (native) Android kurumsal iletişim uygulamasıdır.

---

## 🌟 Temel Yetenekler ve Mimari

### 1. 5 Sekmeli Bütünleşik Ana Ekran (`DialerActivity`)
Uygulama, tüm iletişim ihtiyaçlarını tek bir modern ana aktivite altında 5 ana sekmede toplar:
1. **📞 Tuşlar (Dialer):**
   - Hızlı arama alanı, 0-9, *, # DTMF tuşları ve çağrı kontrolü.
   - Aktif görüşme ekranı (`CallActivity`): Sessize alma (Mute), Hoparlör (Speaker), Bekletme (Hold), Aktarım ve Çağrı Süre Sayacı.
   - Kilit ekranında gelen çağrıyı anında uyandırma (`IncomingCallActivity`, `TurnScreenOn` / `ShowWhenLocked`).
2. **📊 Geçmiş (Call History):**
   - Sunucu CDR kayıtları ile tam senkronize çağrı listesi.
   - Hızlı yön filtreleme çipleri: *Tümü*, *Cevapsız*, *Gelen*, *Giden*.
   - Tek dokunuşla geri arama desteği.
3. **👥 Rehber (Contacts):**
   - Santraldeki tüm dahilileri (50+ dahili) otomatik senkronize eden kurumsal rehber.
   - Anlık çevrimiçi/çevrimdışı varlık (presence) takibi (yeşil/gri durum noktaları).
   - Departman ve rol rozetleri (`admin`, `cc_agent`, `standard_user` vb.).
4. **💬 Sohbet (Chat & Group Chat):**
   - **Bireysel (1-to-1) Sohbet:** Dahililer arası anlık metin, fotoğraf ve belge paylaşımı.
   - **Çok Kullanıcılı Grup Sohbeti:** 256 kişiye kadar ekip ve departman grup odaları.
   - **Hızlı Grup Kurulumu:** `+ Yeni Grup` butonu ve rehberden çoklu üye seçici (`ContactSelectionAdapter`).
   - **Gelişmiş Sohbet Filtreleme:** *Tümü*, *Bireysel*, *Gruplar* filtre çipleri ile anlık geçiş.
   - **Grup Yönetim Modalı:** Katılımcı listesi, yönetici rolleri (`admin`/`member`), üye ekleme/çıkarma, grup başlığı düzenleme ve ayrılma.
   - **Görsel Ayrım:** Grup rozetleri (`Grup`), mor/indigo avatar ikonları (`👥`) ve grup mesajlarında algoritmik renkli gönderen isimleri (`getDeterministicColor`).
   - **Sistem Bildirimleri:** Üye katıldı/ayrıldı/çıkarıldı durumları için özel biçimlendirilmiş sistem balonları.
5. **⚙️ Santral (Features & Diagnostics):**
   - Rahatsız Etmeyin (DND) kontrolü.
   - Çağrı Yönlendirme (Her Zaman, Meşgulde, Cevapsızda) ve çalma süre eşikleri (10–45 sn).
   - Dahili sistem log görüntüleyicisi (`LogViewerActivity` & `AppLogManager`) ile anlık Logcat inceleme ve e-posta/dosya paylaşımı.

---

### 2. Güvenlik & WebRTC Ses Motoru
* 🔒 **Sıfır Bulut Bağımlılığı:** Tüm sinyalleşme doğrudan kendi kurum santraliniz üzerinden şifreli (**WSS / TLS / DTLS-SRTP**) olarak yürütülür.
* 🛡️ **Kısıtlayıcı Ağ ve Güvenlik Duvarı Aşımı:** Kurumsal ağlarda UDP engelli olsa bile Port 443 üzerinden **coturn TURNS** ile kesintisiz ses geçişi.
* 🎙️ **Opus HD Voice:** Düşük bant genişliğinde bile yüksek kaliteli, kristal netliğinde ses iletimi.

---

### 3. Arka Plan Kararlılığı & Push Bildirimleri
* **Foreground Service (`PbxForegroundService`):** Android Doze Mode veya agresif pil tasarrufunun bağlantıyı kesmesini önleyen kalıcı ön plan servisi.
* **Firebase Cloud Messaging (FCM):** Uygulama tamamen kapalıyken dahi gelen çağrılar ve grup/bireysel sohbet mesajları için anlık uyandırma bildirimi.
* **Konuşma Düzeyinde Bildirim Gruplama:** Grup ve bireysel sohbet bildirimleri bildirim panelinde otomatik olarak konuşma bazında kümelenir.

---

## 🛠️ Derleme ve Dağıtım (Build & Deployment)

### Gereksinimler
- Android SDK 36 (Build Tools 36.0.0)
- Java 17 / OpenJDK 17
- Gradle 8.x (Gradle Wrapper dahildir)

### 1. Birim Testlerini Çalıştırma
```bash
cd /home/pbx/android
./gradlew testReleaseUnitTest
```

### 2. İmzalı Release APK Derleme
```bash
./gradlew assembleRelease
```
* Çıktı: `app/build/outputs/apk/release/app-release.apk` (~2.8 MB)
* Otomatik olarak `release.keystore` anahtarıyla imzalanır ve zipalign edilir.

### 3. İmzalı Release App Bundle (AAB) Derleme
```bash
./gradlew bundleRelease
```
* Çıktı: `app/build/outputs/bundle/release/app-release.aab` (~4.2 MB)

### 4. Google Play Console'a Yükleme
Proje kök dizinindeki otomatik Google Play API betiği ile Kapalı Test kanalına tek komutla yüklenir:
```bash
python3 upload_to_play_console.py alpha completed
```
* Sürüm kodu (`versionCode: 32`) ve sürüm adı (`versionName: "1.0.31"`) `build.gradle.kts` üzerinden otomatik okunur.
* Çok dilli sürüm notları (tr-TR ve en-US) Google Play API üzerinden otomatik kaydedilir ve onaylanır.

### 5. Doğrudan Web İndirme Bağlantısı
Üretilen APK, kullanıcıların doğrudan indirebilmesi için web sunucusuna kopyalanır:
```bash
cp app/build/outputs/apk/release/app-release.apk /home/pbx/web/aipbx-latest.apk
```
* Web İndirme URL: `https://<santral-adresi>/aipbx-latest.apk`

---

## 📂 Dizin Yapısı

```
android/
├── app/
│   ├── build.gradle.kts          # Sürüm (v1.0.32 Build 33) ve bağımlılık tanımları
│   └── src/
│       ├── main/
│       │   ├── AndroidManifest.xml # VoIP izinleri, servisler ve ekranlar
│       │   ├── java/com/mhrgl/aipbx/
│       │   │   ├── data/
│       │   │   │   ├── ApiClient.kt            # REST API (Giriş, Rehber, Grup Chat vb.)
│       │   │   │   ├── AppPreferences.kt       # Yerel ayarlar ve oturum verileri
│       │   │   │   ├── ChatWebSocketManager.kt # Go Chat WebSocket istemcisi ve olay dinleyicisi
│       │   │   │   └── SimpleImageLoader.kt    # Hafif görsel önbellekleyici
│       │   │   ├── engine/
│       │   │   │   └── SipWebRtcEngine.kt      # Headless WebRTC JsSIP ses motoru
│       │   │   ├── model/
│       │   │   │   └── Models.kt               # Veri modelleri (Grup, Mesaj, Rehber, CDR vb.)
│       │   │   ├── service/
│       │   │   │   ├── AiPbxFirebaseMessagingService.kt # FCM Push yöneticisi
│       │   │   │   ├── BootReceiver.kt                 # Cihaz açılış tetikleyicisi
│       │   │   │   └── PbxForegroundService.kt         # Kalıcı VoIP ön plan servisi
│       │   │   └── ui/
│       │   │       ├── CallActivity.kt             # Aktif Görüşme Ekranı
│       │   │       ├── ChatActivity.kt             # Grup & Bireysel Sohbet Ekranı
│       │   │       ├── ChatConversationAdapter.kt  # Sohbet listesi adaptörü (Grup rozetli)
│       │   │       ├── ChatListActivity.kt         # Bağımsız Sohbet Aktivitesi
│       │   │       ├── ChatMessageAdapter.kt       # Mesaj balonları & sistem olayları
│       │   │       ├── ContactSelectionAdapter.kt  # Gruba üye ekleme seçim adaptörü
│       │   │       ├── ContactsAdapter.kt          # Kurumsal rehber adaptörü
│       │   │       ├── DialerActivity.kt           # Ana 5 Sekmeli Aktivite (Tuşlar, Sohbet vb.)
│       │   │       ├── GroupParticipantAdapter.kt  # Grup katılımcıları & yönetici menüsü
│       │   │       ├── IncomingCallActivity.kt     # Gelen çağrı kilit ekranı
│       │   │       ├── LoginActivity.kt            # Kullanıcı oturum açma ekranı
│       │   │       ├── LogViewerActivity.kt        # Canlı log ve arıza teşhis ekranı
│       │   │       └── ServerSetupActivity.kt      # Sunucu adresi yapılandırma
│       │   └── res/                                # Layout, renkler, ikonlar ve animasyonlar
│       └── test/                                   # Model ve iş mantığı birim testleri
├── release.keystore              # Üretim imzalama anahtarı
├── upload_to_play_console.py     # Google Play Console API otomatik yayınlama betiği
└── README.md                     # Android dokümantasyonu
```
