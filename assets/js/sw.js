// Service Worker: офлайн-кеш оболонки + пуш-повідомлення
var CACHE = 'starvud-v1';
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(clients.claim()); });
self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET') return;
  var url = new URL(e.request.url);
  if (url.pathname.indexOf('/assets/') !== -1) {
    e.respondWith(
      caches.open(CACHE).then(function (c) {
        return c.match(e.request).then(function (hit) {
          if (hit) return hit;
          return fetch(e.request).then(function (resp) {
            if (resp.ok) c.put(e.request, resp.clone());
            return resp;
          });
        });
      })
    );
  }
});
self.addEventListener('push', function (e) {
  var data = {};
  try { data = e.data.json(); } catch (err) { data = { title: 'Старвуд-М', body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(data.title || 'Старвуд-М', {
    body: data.body || '', icon: 'assets/img/avatar.png', badge: 'assets/img/avatar.png'
  }));
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  e.waitUntil(clients.openWindow('./admin/orders'));
});
