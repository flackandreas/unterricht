const CACHE_NAME = 'schul-app-v3';
const urlsToCache = [
  '/css/app_styles.css',
  '/js/app.js'
];

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.filter(name => name !== CACHE_NAME).map(name => caches.delete(name))
      );
    })
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  // Hausaufgabenfotos und Auswertungen sind personenbezogen und duerfen nicht
  // im Cache des Geraets zurueckbleiben.
  const url = new URL(event.request.url);
  if (url.pathname === '/media.php' || url.pathname.startsWith('/uploads/')) return;

  
  // Network first, fallback to cache for HTML/PHP
  event.respondWith(
    fetch(event.request)
      .then(response => {
        return response;
      })
      .catch(() => {
        return caches.match(event.request)
          .then(cachedResponse => {
            if (cachedResponse) return cachedResponse;
            return new Response('Offline: Bitte stellen Sie eine Internetverbindung her.');
          });
      })
  );
});
