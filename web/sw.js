// Minimal service worker — exists only for the PWA installability requirement,
// caches nothing (so live PBX/call data never goes stale).
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(self.clients.claim()));
self.addEventListener("fetch", () => {}); // every request goes to the network as usual
