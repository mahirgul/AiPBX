# AiPBX iOS Kurumsal İletişim Uygulaması

> **Platform**: iOS 16.0+ (iPhone & iPad)  
> **Mimari**: SwiftUI, WebKit (JsSIP WebRTC Engine), CallKit, URLSession WebSocket, Combine  
> **Paket Kimliği (Bundle ID)**: `com.mhrgl.AiPBX`  
> **Derleme & CI/CD**: GitHub Actions (`macos-14`, Xcode 15/16)  

Bu proje, kurum içi **Asterisk 22 Web PBX** santral sistemiyle tam entegre çalışan, **harici bulut bağımlılığı olmayan**, güvenli, WebRTC tabanlı yerel (native) iOS kurumsal iletişim uygulamasıdır.

---

## 🌟 Temel Yetenekler ve 5 Sekmeli Arayüz

1. **📞 Tuşlar (Dialer & Call Control):**
   - 3x4 DTMF tuş takımı (0-9, *, #) ve hızlı arama alanı.
   - Anlık SIP kayıt ve bağlantı durumu göstergesi (Bağlandı, Bağlanıyor, Bağlantı Yok).
   - Tam ekran aktif çağrı arayüzü (`ActiveCallView`): Mute, Tuş Takımı (DTMF), Hoparlör (Speaker), Beklet (Hold) ve Çağrı Süresi Sayacı.
   - iOS sistem çağrıları ve kilit ekranı entegrasyonu (`CallKit` & `AudioSessionManager`).

2. **📊 Geçmiş (Call History):**
   - Santral CDR kayıtları ile tam senkronize çağrı listesi (`/api/mobile/history.php`).
   - Yön filtreleri: *Tümü*, *Cevapsız*, *Gelen*, *Giden*.
   - Tek dokunuşla geri arama desteği ve arama süresi göstergesi.

3. **👥 Rehber (Contacts):**
   - Kurumsal tüm dahili listesinin otomatik senkronizasyonu (`/api/mobile/contacts.php`).
   - Anlık çevrimiçi/çevrimdışı varlık (presence) takibi (yeşil/gri durum rozetleri).
   - Rol rozetleri (`Yönetici`, `Temsilci`, `Standart`).
   - Tek dokunuşla sesli arama veya anlık sohbet başlatma.

4. **💬 Sohbet (Chat & Group Chat):**
   - **Bireysel (1-to-1) Sohbet:** Dahililer arası anlık şifreli mesajlaşma.
   - **Çok Katılımcılı Grup Sohbeti:** Departman ve ekip odaları oluşturma (`+ Yeni Grup`).
   - **Gerçek Zamanlı İletişim:** `URLSessionWebSocketTask` üzerinden anlık mesaj, yazıyor bilgisi (`typing`) ve varlık takibi.
   - **Grup Yönetimi:** Katılımcı listesi, yönetici rolleri ve gruptan ayrılma.
   - **Filtre Çipleri:** *Tümü*, *Bireysel*, *Gruplar*.

5. **⚙️ Santral (PBX Features & Diagnostics):**
   - Rahatsız Etmeyin (DND) anahtarı (`/api/mobile/features.php`).
   - Çağrı Yönlendirme (Her Zaman, Meşgulde, Cevapsızda) ve çalma süre eşikleri (10–45 sn).
   - Dahili Sistem Log Görüntüleyicisi (`LogViewerView` & `AppLogManager`): Anlık hata ayıklama ve iOS Paylaşım Menüsü (`ShareSheet`) ile log dışa aktarma.

---

## 🔔 Push Bildirimleri (APNs + PushKit VoIP)

Android'deki FCM katmanının iOS karşılığı. Uygulama giriş yapınca iki token'ı `/api/mobile/fcm_token.php` ile santrale kaydeder (`push_type = apns`):

* **APNs token'ı:** sohbet mesajları, gruba eklenme ve test bildirimi. Bildirimdeki **Yanıtla** alanı uygulamayı açmadan çalışır.
* **PushKit VoIP token'ı:** gelen arama. Push gelince arama hemen CallKit ekranında çalar, uygulama arka planda oturumu yeniler ve SIP kaydını yapar; santral mobil kaydı görünce INVITE'ı gönderir. INVITE gelmeden "Cevapla" denirse arama gelir gelmez açılır.

Oturum (sunucu + 30 günlük token) Keychain'de tutulur; iOS uygulamayı push için kapalıyken başlatsa da `/api/mobile/refresh.php` ile yeniden giriş yapılır. Çıkışta token'lar sunucudan silinir.

**Apple tarafında gerekenler (tahmin edilemez, portalda girilir — Mobil Bildirim → APNs):**

1. Apple Developer → Keys → "Apple Push Notifications service (APNs)" seçili bir **.p8 anahtarı**; **Key ID** ve **Team ID**.
2. Uygulamanın imzalandığı **bundle ID** (varsayılan `com.mhrgl.AiPBX`); App ID'de *Push Notifications* yeteneği açık olmalı.
3. **Ortam:** geliştirme profiliyle imzalı derlemeler için *Sandbox*, App Store / TestFlight / ad hoc / kurumsal için *Production*.

CI'daki imzasız IPA'da `aps-environment` yetkisi yoktur; push almak için uygulama, push yeteneği olan bir profille yeniden imzalanmalıdır (ücretsiz hesaplı AltStore/Sideloadly imzaları bunu genellikle desteklemez).

---

## 🛠️ GitHub Actions ile Otomatik Derleme (CI/CD)

Uygulama, `.github/workflows/ios-build.yml` iş akışı sayesinde doğrudan GitHub üzerinde **macOS M1/M2 (macos-14)** koşucuları üzerinde derlenir:

* **Tetikleyiciler:**
  - `ios/**` dizinine kod push edildiğinde
  - GitHub Actions arayüzünden manuel olarak (**Run workflow**) tetiklendiğinde
* **Üretilen Çıktılar (Artifacts):**
  - `AiPBX-unsigned.ipa`: Gerçek iOS cihazlara (TrollStore, AltStore, Sideloadly veya Kurumsal MDM ile) yüklenebilir IPA paketi.
  - `AiPBX-Simulator.zip`: macOS Xcode Simulator ortamında doğrudan sürüklenebilir ve çalıştırılabilir `.app` paketi.

---

## 💻 Yerel (Local) macOS Ortamında Derleme

Eğer Mac üzerinde çalıştırmak veya geliştirmek isterseniz:

```bash
# 1. Projeyi klonlayın
git clone https://github.com/mahirgul/AiPBX.git
cd AiPBX/ios

# 2. xcodegen ile projeyi yenileyin (İsteğe bağlı, xcodeproj zaten hazırdır)
brew install xcodegen
xcodegen generate

# 3. Xcode ile açın
open AiPBX.xcodeproj
```
