/* =====================================================================
 * Service Worker — GAHC Smart Outlet Ordering (Staff PWA)
 * - Cache shell aplikasi staf (network-first => update cPanel langsung berlaku)
 * - Listener Web Push (background / layar terkunci)
 * Naikkan CACHE_VERSION setiap kali ada update file frontend.
 * ===================================================================== */
const CACHE_VERSION = 'gahc-staff-v1.2.1';
const SCOPE_URL = new URL(self.registration.scope);
const PRECACHE = [
  'staff.html',
  'manifest-staff.json',
  'assets/css/app.css',
  'assets/js/staff.js',
  'assets/icons/icon-192.png',
  'assets/icons/badge-72.png',
].map((p) => new URL(p, SCOPE_URL).toString());

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((cache) => Promise.all(PRECACHE.map((u) => cache.add(new Request(u, { cache: 'reload' })).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('gahc-') && k !== CACHE_VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== SCOPE_URL.origin) return;           // CDN / pihak ketiga: biarkan browser
  if (url.pathname.includes('/api/')) return;            // API selalu langsung ke server (real-time)
  if (url.pathname.endsWith('/setup.php')) return;
  if (url.pathname.includes('/uploads/')) return;

  // Network-first: selalu ambil versi terbaru, fallback ke cache saat offline.
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res && res.ok && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE_VERSION).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      })
      .catch(() =>
        caches.match(req, { ignoreSearch: true }).then((hit) => {
          if (hit) return hit;
          if (req.mode === 'navigate') return caches.match(new URL('staff.html', SCOPE_URL).toString());
          return Response.error();
        })
      )
  );
});

/* --------------------------- Web Push ------------------------------ */
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: '🔔 Pesanan Baru', body: event.data ? event.data.text() : '' };
  }
  const title = data.title || '🔔 Pesanan Baru';
  const options = {
    body: data.body || 'Ada pesanan baru masuk.',
    icon: new URL('assets/icons/icon-192.png', SCOPE_URL).toString(),
    badge: new URL('assets/icons/badge-72.png', SCOPE_URL).toString(),
    vibrate: [300, 150, 300, 150, 400],
    requireInteraction: true,
    renotify: true,
    tag: data.tag || 'gahc-order',
    timestamp: Date.now(),
    data: { url: new URL(data.url || 'staff.html', SCOPE_URL).toString(), order_code: data.order_code || null },
  };
  event.waitUntil(
    self.registration.showNotification(title, options).then(() =>
      // beri tahu tab staf yang terbuka agar langsung refresh
      self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) =>
        list.forEach((c) => c.postMessage({ type: 'push_received', order_code: data.order_code || null }))
      )
    )
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || new URL('staff.html', SCOPE_URL).toString();
  const orderCode = event.notification.data && event.notification.data.order_code;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if (client.url.includes('staff.html') && 'focus' in client) {
          client.postMessage({ type: 'open_order', order_code: orderCode });
          return client.focus();
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(target) : null;
    })
  );
});
