# AiPBX — Değişiklikler

Her sürüm `scripts/release.sh X.Y.Z` ile yayınlanır; aşağıdaki bölüm o sürümün
git etiketi açıklaması olur ve kurulumlarda **Sistem Güncelleme** sayfasında
"Yenilikler" olarak görünür. Kurulu bir sistemi güncellemek için:
`sudo aipbx-update` (veya portal → Yönetim → Sistem Güncelleme).

## 2.0.0

- Sürümleme ve güncelleme: `aipbx-update` komutu ve portalda Sistem Güncelleme
  sayfası (yedek → güncelleme → doğrulama, hata olursa otomatik geri dönüş).
- `install.sh --upgrade`: mevcut kurulumu şifreleri yeniden üretmeden günceller.
- Güvenlik: mobil ve sohbet oturumları şifre değişince kapanır; mobil girişte
  iki adımlı doğrulama; Google girişinde token hedefi / e-posta doğrulaması;
  faksta arayan numarasıyla komut çalıştırma açığı kapatıldı; sohbet dosyalarına
  erişim sahiplikle sınırlandı; passkey'de biyometri/PIN zorunlu.
- Çağrı merkezi: mola Asterisk'e gerçekten uygulanıyor; dinle/fısılda/dahil ol
  panoda ve çalışıyor; çalan telefon "Çalıyor" görünüyor; pano küçük ekranlarda
  taşmıyor.
- Yeni kurulum: veritabanı migration'larla kuruluyor; CDR / kuyruk kayıtları,
  TLS/WSS, faks alma-gönderme, kayıt dinleme ve sesli mesaj izinleri düzeltildi.
