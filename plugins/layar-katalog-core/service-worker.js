/* Web Push only: this service worker does not cache or intercept site requests. */
self.addEventListener('push', function (event) {
  var payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = { body: event.data ? event.data.text() : '' };
  }

  var target = '/';
  try {
    var requested = new URL(payload.url || '/', self.location.origin);
    if (requested.origin === self.location.origin) target = requested.href;
  } catch (error) {
    target = '/';
  }

  var icon;
  if (typeof payload.icon === 'string' && payload.icon) {
    try {
      var requestedIcon = new URL(payload.icon, self.location.origin);
      if (requestedIcon.origin === self.location.origin) icon = requestedIcon.href;
    } catch (error) {
      icon = undefined;
    }
  }

  var title = String(payload.title || 'Episode baru').slice(0, 80);
  var options = {
    body: String(payload.body || 'Ada pembaruan baru di katalog.').slice(0, 220),
    tag: String(payload.tag || 'lkc-new-episode').slice(0, 80),
    data: { url: target },
    renotify: false
  };
  if (icon) options.icon = icon;
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = '/';
  try {
    var requested = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin);
    if (requested.origin === self.location.origin) target = requested.href;
  } catch (error) {
    target = '/';
  }

  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
    for (var i = 0; i < clients.length; i++) {
      if (clients[i].url === target && 'focus' in clients[i]) return clients[i].focus();
    }
    return self.clients.openWindow(target);
  }));
});
