const CACHE_NAME = 'schul-app-v5';
const urlsToCache = [
  '/css/app_styles.css',
  '/js/app.js',
  '/js/live.js'
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

  // Erfassungsschirm und Auswertung tragen die komplette Klassenliste im
  // Klartext. Der Code darf in den Cache, die Namen nicht - der Ausgangskorb
  // in localStorage kommt ohnehin mit Kennungen statt Namen aus.
  if (url.pathname === '/live.php' || url.pathname === '/live_report.php') return;

  // Network first, fallback to cache for HTML/PHP
  event.respondWith(
    fetch(event.request)
      .then(response => {
        // Die hinterlegte Fassung mitziehen, solange Netz da ist. Ohne das
        // bleibt im Cache ewig der Stand vom ersten Besuch stehen:
        // CACHE_NAME aendert sich nur von Hand, und install() laeuft nur,
        // wenn sich diese Datei aendert.
        if (urlsToCache.includes(url.pathname) && response.ok) {
          const kopie = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(url.pathname, kopie));
        }

        return response;
      })
      .catch(() => {
        // ignoreSearch, weil asset() an jede Adresse ein ?v=<Zeitstempel>
        // haengt: /js/live.js?v=1699... Im Cache liegt /js/live.js, und
        // ohne diese Angabe hat caches.match() nie getroffen - der
        // Offline-Cache war seit Einfuehrung der Versionierung wirkungslos.
        return caches.match(event.request, { ignoreSearch: true })
          .then(cachedResponse => {
            if (cachedResponse) return cachedResponse;
            return new Response('Offline: Bitte stellen Sie eine Internetverbindung her.');
          });
      })
  );
});
