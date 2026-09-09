# Test Süreci

## Duman testi

Tek komutla "hiçbir şey patlamıyor mu?" kontrolü. Her commit'te otomatik çalışır.

    php bin/smoke.php                    # tümü (~18 sn)
    php bin/smoke.php --only=routes      # sadece sayfa render (~12 sn)
    php bin/smoke.php --only=rbac        # sadece yetki (~2 sn)
    php bin/smoke.php --only=conventions # sadece mimari kurallar (<1 sn)
    php bin/smoke.php --only=lint        # sadece sözdizimi/dil/JS (~4 sn)
    php bin/smoke.php --route=/queues    # tek bir rota (hata ayıklama)

Çıkış kodu: `0` temiz, `1` en az bir hata.

### Ne kontrol eder

| Grup | Kontrol |
|---|---|
| **rota** | Her rota TR ve EN'de render ediliyor mu — fatal yok, PHP uyarısı yok, çıktı şaşırtıcı derecede kısa değil, HTML `</html>` ile kapanıyor |
| **rbac** | Admin-only sayfalar düşük yetkili rolle engelleniyor mu (admin'in aynı sayfayı görebildiği "kanarya" ile birlikte) |
| **konvansiyon** | 5 mimari kural — ertelenmiş reload, FileHelper, marka, yetki kontrolü, api guard |
| **lint** | `php -l` (196 dosya), tr/en anahtar simetrisi, JS parantez dengesi |

Rota listesi `src/routes.php`'den okunur — **yeni sayfa eklendiğinde test kapsamı
kendiliğinden büyür**, elle liste güncellemek gerekmez.

### Nasıl çalışır

Her rota ayrı bir PHP alt-sürecinde (`bin/_smoke_render.php`) render edilir.
Ayrı süreç şart: yönlendiren controller'lar `header()+exit` çağırıyor ve `exit`
tek süreçte koşan bir runner'ı öldürürdü; fatal error'lar da böylece izole olur.

PHP uyarıları **çıktıda aranmaz**, alt-süreçteki `set_error_handler` ile
programatik yakalanır — uygulama bilinçli olarak `display_errors=Off` ile
çalıştığı için uyarılar HTML'e hiç basılmaz.

### Güvenlik

Duman testi yalnızca **okur ve render eder**. Hiçbir POST, servis yazımı,
Asterisk config üretimi veya reload'u tetiklemez. Bilinen tek yan etki:
`requireLogin()` → `_touchLastSeen()` admin'in `last_seen_at` alanını günceller.

`root` olarak çalıştırılır — `bin/` dizini sertleştirme gereği `drwxr-x--- root:root`.

## Birim testleri

    php vendor/bin/phpunit
    php vendor/bin/phpunit --filter SanitizationTest

| Test | Neyi kilitler |
|---|---|
| `SanitizationTest` | `toCleanAscii` / `sanitizeDestType` — config injection savunması |
| `ValidationTest` | firewall/fail2ban IP-CIDR ve korumalı port aralığı doğrulaması |
| `SyncGeneratorTest` | PJSIP trunk üretecinin çıktısı, enjeksiyon direnci, idempotentliği |
| `PendingSyncTest` | Ertelenmiş reload sözleşmesi (işaretleme config'e dokunmaz) |
| `RbacTest` | Yetki yükseltme devre kesicisi — DB'de izin verilse bile admin dışı reddedilir |

Her test dosyası, geçmişte **gerçekten yaşanmış** bir hatanın tekrarlanmasını
engellemek için yazıldı; başlıklarında olay tarihi var.

### İzolasyon

Testler yalnızca `asterisk_test` veritabanında ve `/tmp/aipbx-test-conf`
dizininde çalışır. `tests/bootstrap.php` üç kilit kurar:

1. `DB_NAME` `asterisk_test` değilse testler **hiç başlamaz**
2. `ASTERISK_PBX_DIR` geçici dizine yönlendirilir — canlı `/etc/asterisk`'e yazım yok
3. `AIPBX_NO_ASTERISK=1` — canlı Asterisk'e CLI komutu (reload dahil) gitmez

Üçüncü kilit şart: `syncAllTrunks()` gibi üreteçler `writeConfWithRollback()`
içinden `pjsip reload` tetikliyor. `SyncGeneratorTest` bu kilitlerin kurulu
olduğunu ilk iki testinde ayrıca doğrular.

## Kurulum (yeni klon / sunucu)

    composer install
    bash bin/setup-test-db.sh     # asterisk_test veritabanını kurar
    bash bin/install-hooks.sh     # pre-commit hook'unu kurar

Hook, commit öncesi duman testini ve (test veritabanı kuruluysa) birim
testlerini koşar; başarısızsa commit'i durdurur. Gerçekten gerekiyorsa
`git commit --no-verify` ile atlanabilir — ama alışkanlık hâline getirilmemeli.

## Kapsam dışı (bilerek)

Tarayıcı/SPA davranışı, WebRTC ses yolu ve gerçek çağrı/faks akışları otomatik
test edilmez. Bunlar anlamlı şekilde otomatikleştirilemediği için elle test
edilmeye devam eder (`pjsua` test istemcileri, gerçek arama/faks denemeleri).
