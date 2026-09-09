// Minimal service worker — sadece PWA yüklenebilirlik şartı için var,
// hiçbir şeyi önbelleklemiyor (canlı santral/çağrı verisi bayat kalmasın diye).
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(self.clients.claim()));
self.addEventListener("fetch", () => {}); // her istek normal ağdan geçer
