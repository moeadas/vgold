// VGo Service Worker
self.addEventListener('push', function(event) {
  if (!event.data) return;
  try {
    var data = event.data.json();
    event.waitUntil(
      self.registration.showNotification(data.title || 'VGo', {
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
// PWA lifecycle — activate promptly
self.addEventListener('install', (e) => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
