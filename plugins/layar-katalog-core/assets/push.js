(function () {
  'use strict';

  var settings = window.LKCPush || {};
  var strings = settings.strings || {};

  function setState(root, button, status, subscribed) {
    button.textContent = subscribed ? (strings.disable || 'Matikan notifikasi episode') : (strings.enable || 'Aktifkan notifikasi episode');
    button.setAttribute('aria-pressed', subscribed ? 'true' : 'false');
    if (status) status.textContent = subscribed ? (strings.active || 'Notifikasi episode aktif di browser ini.') : (strings.inactive || 'Notifikasi hanya aktif setelah Anda menyetujuinya.');
    root.setAttribute('data-subscribed', subscribed ? 'true' : 'false');
  }

  function base64UrlToBytes(value) {
    var padding = '='.repeat((4 - value.length % 4) % 4);
    var base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(base64);
    var output = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);
    return output;
  }

  function arraysEqual(a, b) {
    if (!a || !b || a.length !== b.length) return false;
    for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
    return true;
  }

  function requestJson(url, method, body) {
    return window.fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) throw new Error((data && data.message) || strings.error || 'Notifikasi belum berhasil diatur.');
        return data;
      });
    });
  }

  function initializeToggle(root) {
    var button = root.querySelector('[data-lkc-push-button]');
    var status = root.querySelector('[data-lkc-push-status]');
    if (!button) return;

    if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      button.disabled = true;
      if (status) status.textContent = strings.unsupported || 'Browser ini tidak mendukung Web Push atau situs belum memakai HTTPS.';
      return;
    }

    function registerServiceWorker() {
      return navigator.serviceWorker.register(settings.serviceWorkerUrl);
    }

    function getExistingRegistration() {
      return navigator.serviceWorker.getRegistration(settings.serviceWorkerUrl);
    }

    function updateFromBrowser() {
      return getExistingRegistration().then(function (registration) {
        if (!registration) {
          setState(root, button, status, false);
          return null;
        }
        return registration.pushManager.getSubscription();
      }).then(function (subscription) {
        setState(root, button, status, Boolean(subscription) && Notification.permission === 'granted');
      }).catch(function () {
        if (status) status.textContent = strings.error || 'Notifikasi belum berhasil diatur. Coba lagi nanti.';
      });
    }

    function disablePush(registration, subscription) {
      return requestJson(settings.unsubscribeUrl, 'POST', { endpoint: subscription.endpoint })
        .catch(function () { return null; })
        .then(function () { return subscription.unsubscribe(); })
        .then(function () { setState(root, button, status, false); })
        .catch(function () { if (status) status.textContent = strings.error || 'Notifikasi belum berhasil diatur.'; });
    }

    function enablePush(registration, existingSubscription) {
      if (Notification.permission === 'denied') {
        if (status) status.textContent = strings.permission || 'Izin notifikasi ditolak. Ubah izin situs ini di pengaturan browser.';
        return Promise.resolve();
      }
      var permissionPromise = Notification.permission === 'granted' ? Promise.resolve('granted') : Notification.requestPermission();
      return permissionPromise.then(function (permission) {
        if (permission !== 'granted') {
          if (status) status.textContent = strings.permission || 'Izin notifikasi tidak diberikan.';
          return null;
        }
        return requestJson(settings.keyUrl, 'GET').then(function (response) {
          if (!response.publicKey) throw new Error(strings.error || 'Kunci VAPID tidak tersedia.');
          var applicationServerKey = base64UrlToBytes(response.publicKey);
          var oldKey = existingSubscription && existingSubscription.options ? existingSubscription.options.applicationServerKey : null;
          var keepExisting = oldKey && arraysEqual(new Uint8Array(oldKey), applicationServerKey);
          var prepareSubscription = Promise.resolve(existingSubscription);
          if (existingSubscription && !keepExisting) {
            prepareSubscription = requestJson(settings.unsubscribeUrl, 'POST', { endpoint: existingSubscription.endpoint })
              .catch(function () { return null; })
              .then(function () { return existingSubscription.unsubscribe(); })
              .then(function () { return null; });
          }
          return prepareSubscription.then(function (subscription) {
            return subscription || registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationServerKey });
          }).then(function (subscription) {
            var payload = subscription.toJSON();
            payload.contentEncoding = 'aes128gcm';
            return requestJson(settings.subscribeUrl, 'POST', payload).then(function () {
              setState(root, button, status, true);
            });
          });
        });
      });
    }

    button.addEventListener('click', function () {
      button.disabled = true;
      if (status) status.textContent = 'Memproses…';
      registerServiceWorker().then(function (registration) {
        return registration.pushManager.getSubscription().then(function (subscription) {
          if (subscription && Notification.permission === 'granted') return disablePush(registration, subscription);
          return enablePush(registration, subscription);
        });
      }).catch(function (error) {
        if (status) status.textContent = error.message || strings.error || 'Notifikasi belum berhasil diatur. Coba lagi nanti.';
      }).finally(function () {
        button.disabled = false;
      });
    });

    updateFromBrowser();
  }

  function initialize() {
    document.querySelectorAll('[data-lkc-push-toggle]').forEach(initializeToggle);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
