const CACHE_NAME = 'scholarlink-v3'; // ✨ Bumped version to v3 to force update!
const urlsToCache = [
  '/assets/img/tau_logo.png'
];

// Install Service Worker
self.addEventListener('install', event => {
  self.skipWaiting(); // Piliting i-activate agad ang bagong service worker
});

// ✨ THE FIX: Network-First Strategy for Dynamic PHP Pages
self.addEventListener('fetch', event => {
  // Kung Web Page ang binubuksan (tulad ng index.php)
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          // Kapag may internet, kunin ang fresh data at i-save sa cache
          return caches.open(CACHE_NAME).then(cache => {
            cache.put(event.request, response.clone());
            return response;
          });
        })
        .catch(() => {
          // Kapag WALANG internet ang student, saka lang gagamitin ang offline cache
          return caches.match(event.request);
        })
    );
  } else {
    // Para sa mga Images at static files, Cache-First pa rin para mabilis mag-load
    event.respondWith(
      caches.match(event.request).then(response => {
        return response || fetch(event.request);
      })
    );
  }
});

// Linisin ang mga lumang caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  self.clients.claim();
});