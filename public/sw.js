// VGo Service Worker
// Bump CACHE_VERSION whenever the shell assets change to invalidate old caches.
const CACHE_VERSION = 'vgold-v3';
const APP_SHELL = [
  '/',
  '/manifest.json',
  '/assets/img/vgo-logo.png',
  '/assets/img/icon-192.png',
  '/assets/img/icon-512.png',
];

// ===== Web push =====
self.addEventListener('push', function(event) {
  if (!event.data) return;
  try {
    var data = event.data.json();
    event.waitUntil(
      self.registration.showNotification(data.title || 'VGold', {
        body: data.body || '',
        icon: '/assets/img/vgo-logo.png',
        badge: '/assets/img/vgo-logo.png',
        tag: data.tag || 'vgo-notif',
        data: data,
      })
    );
  } catch(e) {}
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  if (!event.notification.data || !event.notification.data.link) return;
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if (client.url.includes('vgo') && 'focus' in client) {
          client.focus();
          client.postMessage({ type: 'NOTIFICATION_CLICK', data: event.notification.data });
          return;
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(event.notification.data.link);
      }
    })
  );
});

// ===== PWA lifecycle =====
self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_VERSION).then(function(cache) {
      return cache.addAll(APP_SHELL).catch(function(){ /* tolerate a missing asset */ });
    }).then(function(){ return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.filter(function(k){ return k !== CACHE_VERSION; })
                            .map(function(k){ return caches.delete(k); }));
    }).then(function(){ return self.clients.claim(); })
  );
});

// ===== Offline support (network-first) =====
// Online users ALWAYS get fresh content (network is tried first); the cache is
// only used as a fallback when the network is unavailable. API calls, non-GET
// requests, and cross-origin requests are never cached.
self.addEventListener('fetch', function(event) {
  var req = event.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;                 // only same-origin
  if (url.pathname.indexOf('/api/') === 0 ||
      url.pathname.indexOf('/crm/api/') !== -1 ||
      url.pathname.indexOf('/crm/') === 0) return;                 // never cache API / CRM pages

  event.respondWith(
    fetch(req).then(function(res) {
      if (res && res.status === 200 && res.type === 'basic') {
        var copy = res.clone();
        caches.open(CACHE_VERSION).then(function(cache){ cache.put(req, copy); });
      }
      return res;
    }).catch(function() {
      return caches.match(req).then(function(hit) {
        return hit || (req.mode === 'navigate' ? caches.match('/') : undefined);
      });
    })
  );
});
