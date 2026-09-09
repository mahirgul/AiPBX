<?php
/**
 * api/ katmanı için ortak minimum güvenlik girişi — JSON/fetch() tabanlı uç
 * noktalar (sayfaya değil, tarayıcı JS'ine yanıt veren dosyalar) için.
 *
 * api/cc.php BUNU KULLANMAZ — o, action bazlı requireRole()+CSRF akışını
 * kendi içinde daha nüanslı biçimde yönetiyor (bkz. cc.php üstündeki not).
 * fax_download.php/sound_play.php gibi TARAYICI TARAFINDAN DOĞRUDAN
 * navigasyonla açılan (img src / a href / audio src) uç noktalar da bunu
 * kullanmaz — onlar için oturum süresi dolunca JSON değil, sayfaya
 * redirect (requireLogin()) doğru davranıştır.
 *
 * Her yeni JSON api/*.php dosyası requireLogin()/requireApiLogin() eklemeyi
 * unutabilir (bu tam olarak destinations.php'de 2026-08-21'de bir kez
 * gerçekleşmişti) — bunun yerine sadece bu dosyayı require etmek, o riski
 * dosya bazlı disiplinden tek bir zorunlu giriş noktasına taşır. Modül-özel
 * ek RBAC/sahiplik kontrolleri (destinations.php'nin hasModulePermission()
 * kontrolü gibi) hâlâ ilgili dosyada, bu noktadan SONRA yapılmaya devam eder.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
requireApiLogin();
