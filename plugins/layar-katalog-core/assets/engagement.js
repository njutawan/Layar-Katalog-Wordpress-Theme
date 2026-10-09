(function () {
  'use strict';

  var settings = window.LKCEngagement || {};
  var watchlistKey = 'lkc_watchlist_v1';
  var historyKey = 'lkc_history_v1';
  var strings = settings.strings || {};

  function safeUrl(value) {
    try {
      var url = new URL(String(value || ''), window.location.href);
      if (url.origin !== window.location.origin || (url.protocol !== 'https:' && url.protocol !== 'http:')) return '';
      return url.href;
    } catch (error) {
      return '';
    }
  }

  function readItems(key) {
    try {
      var data = JSON.parse(window.localStorage.getItem(key) || '[]');
      if (!Array.isArray(data)) return [];
      return data.filter(function (item) {
        return item && Number(item.id) > 0 && safeUrl(item.url) && typeof item.title === 'string';
      });
    } catch (error) {
      return [];
    }
  }

  function writeItems(key, items) {
    try {
      window.localStorage.setItem(key, JSON.stringify(items));
      return true;
    } catch (error) {
      return false;
    }
  }

  function formatDate(timestamp) {
    var date = new Date(Number(timestamp) || 0);
    if (!Number.isFinite(date.getTime()) || !date.getTime()) return '';
    try {
      return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    } catch (error) {
      return date.toLocaleString();
    }
  }

  function makeNode(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (typeof text === 'string') node.textContent = text;
    return node;
  }

  function renderList(container, items, isHistory) {
    var list = container.querySelector('[data-lkc-list-items]');
    var empty = container.querySelector('[data-lkc-list-empty]');
    var clear = container.querySelector('[data-lkc-clear-list]');
    if (!list) return;
    list.replaceChildren();
    if (empty) empty.hidden = items.length > 0;
    if (clear) clear.hidden = items.length === 0;

    items.forEach(function (item) {
      var row = makeNode('li', 'lkc-local-list__item');
      var link = makeNode('a', 'lkc-local-list__link');
      link.href = safeUrl(item.url) || '#';

      var imageUrl = safeUrl(item.image);
      if (imageUrl) {
        var image = makeNode('img', 'lkc-local-list__image');
        image.src = imageUrl;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        link.appendChild(image);
      }

      var copy = makeNode('span', 'lkc-local-list__copy');
      copy.appendChild(makeNode('span', 'lkc-local-list__title', item.title));
      var meta = makeNode('span', 'lkc-local-list__meta');
      if (isHistory) {
        if (item.parentTitle && safeUrl(item.parentUrl)) {
          var parentLink = makeNode('a', 'lkc-local-list__parent', item.parentTitle);
          parentLink.href = safeUrl(item.parentUrl);
          meta.appendChild(parentLink);
          meta.appendChild(document.createTextNode(' · '));
        }
        meta.appendChild(document.createTextNode(formatDate(item.viewedAt)));
      } else {
        meta.textContent = formatDate(item.addedAt);
      }
      copy.appendChild(meta);
      link.appendChild(copy);
      row.appendChild(link);

      var remove = makeNode('button', 'lkc-local-list__remove', 'Hapus');
      remove.type = 'button';
      remove.setAttribute('data-lkc-remove-id', String(item.id));
      remove.setAttribute('aria-label', (isHistory ? 'Hapus dari riwayat: ' : 'Hapus dari watchlist: ') + item.title);
      row.appendChild(remove);
      list.appendChild(row);
    });
  }

  function updateWatchlistButtons() {
    var saved = readItems(watchlistKey);
    var ids = new Set(saved.map(function (item) { return Number(item.id); }));
    document.querySelectorAll('[data-lkc-watchlist-toggle]').forEach(function (button) {
      var exists = ids.has(Number(button.getAttribute('data-id')));
      button.setAttribute('aria-pressed', exists ? 'true' : 'false');
      button.textContent = exists ? 'Hapus dari watchlist' : 'Simpan ke watchlist';
    });
  }

  function renderAllLists() {
    var watchlist = readItems(watchlistKey);
    var history = readItems(historyKey);
    document.querySelectorAll('[data-lkc-watchlist-list]').forEach(function (container) {
      renderList(container, watchlist, false);
    });
    document.querySelectorAll('[data-lkc-history-list]').forEach(function (container) {
      renderList(container, history, true);
    });
    updateWatchlistButtons();
  }

  function announce(container, text) {
    var status = container.querySelector('[data-lkc-list-status]');
    if (status) status.textContent = text;
  }

  function initializeLocalEngagement() {
    document.querySelectorAll('[data-lkc-watchlist-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        var id = Number(button.getAttribute('data-id'));
        var title = button.getAttribute('data-title') || '';
        var url = safeUrl(button.getAttribute('data-url'));
        var image = safeUrl(button.getAttribute('data-image'));
        if (!id || !title || !url) return;

        var items = readItems(watchlistKey);
        var existing = items.some(function (item) { return Number(item.id) === id; });
        if (existing) {
          items = items.filter(function (item) { return Number(item.id) !== id; });
        } else {
          items.unshift({ id: id, title: title, url: url, image: image, addedAt: Date.now() });
          items = items.slice(0, 250);
        }
        if (!writeItems(watchlistKey, items)) {
          var status = button.parentElement.querySelector('[data-lkc-watchlist-status]');
          if (status) status.textContent = strings.storageError || 'Penyimpanan browser tidak tersedia.';
          return;
        }
        renderAllLists();
        var live = button.parentElement.querySelector('[data-lkc-watchlist-status]');
        if (live) live.textContent = existing ? (strings.removed || 'Dihapus dari watchlist.') : (strings.added || 'Ditambahkan ke watchlist.');
      });
    });

    document.querySelectorAll('[data-lkc-watchlist-list], [data-lkc-history-list]').forEach(function (container) {
      container.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-lkc-remove-id]');
        var clear = event.target.closest('[data-lkc-clear-list]');
        var historyList = container.hasAttribute('data-lkc-history-list');
        var key = historyList ? historyKey : watchlistKey;
        if (clear) {
          if (!window.confirm(strings.clearConfirm || 'Hapus semua item dari daftar ini?')) return;
          if (!writeItems(key, [])) {
            announce(container, strings.storageError || 'Penyimpanan browser tidak tersedia.');
            return;
          }
          renderAllLists();
          announce(container, 'Daftar berhasil dikosongkan.');
        } else if (remove) {
          var removeId = Number(remove.getAttribute('data-lkc-remove-id'));
          var filtered = readItems(key).filter(function (item) { return Number(item.id) !== removeId; });
          if (!writeItems(key, filtered)) {
            announce(container, strings.storageError || 'Penyimpanan browser tidak tersedia.');
            return;
          }
          renderAllLists();
          announce(container, strings.removed || 'Item dihapus.');
        }
      });
    });

    var marker = document.querySelector('[data-lkc-history-entry]');
    if (marker) {
      var entry = {
        id: Number(marker.getAttribute('data-id')),
        title: marker.getAttribute('data-title') || '',
        url: safeUrl(marker.getAttribute('data-url')),
        parentTitle: marker.getAttribute('data-parent-title') || '',
        parentUrl: safeUrl(marker.getAttribute('data-parent-url')),
        image: safeUrl(marker.getAttribute('data-image')),
        viewedAt: Date.now()
      };
      if (entry.id && entry.title && entry.url) {
        var history = readItems(historyKey).filter(function (item) { return Number(item.id) !== entry.id; });
        history.unshift(entry);
        writeItems(historyKey, history.slice(0, 50));
      }
    }

    window.addEventListener('storage', renderAllLists);
    renderAllLists();
  }

  function initializeRatings() {
    document.querySelectorAll('[data-lkc-user-rating]').forEach(function (ratingBox) {
      var form = ratingBox.querySelector('[data-lkc-rating-form]');
      if (!form) return;
      var input = form.querySelector('[data-lkc-rating-input]');
      var output = form.querySelector('[data-lkc-rating-output]');
      var status = form.querySelector('[data-lkc-rating-status]');
      var average = ratingBox.querySelector('[data-lkc-rating-average]');
      var count = ratingBox.querySelector('[data-lkc-rating-count]');
      var login = ratingBox.querySelector('[data-lkc-rating-login]');
      var loadStatus = ratingBox.querySelector('[data-lkc-rating-load]');
      var requestHeaders = {};
      if (settings.nonce) requestHeaders['X-WP-Nonce'] = settings.nonce;
      if (input && output) {
        input.addEventListener('input', function () { output.value = input.value; output.textContent = input.value; });
      }
      var ratingUrl = ratingBox.getAttribute('data-rating-url');
      if (ratingUrl) {
        window.fetch(ratingUrl, { credentials: 'same-origin', headers: requestHeaders }).then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) throw new Error((data && data.message) || strings.ratingFailed || 'Gagal memuat rating.');
            return data;
          });
        }).then(function (data) {
          if (average) average.textContent = data.count ? Number(data.average).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '—';
          if (count) count.textContent = Number(data.count || 0).toLocaleString('id-ID');
          if (data.can_rate) {
            form.hidden = false;
            if (login) login.hidden = true;
            if (Number(data.my_rating) >= 1 && Number(data.my_rating) <= 10 && input) input.value = String(data.my_rating);
            if (output && input) { output.value = input.value; output.textContent = input.value; }
          } else {
            form.hidden = true;
            if (login) login.hidden = false;
          }
          if (loadStatus) loadStatus.hidden = true;
        }).catch(function () {
          if (loadStatus) loadStatus.textContent = strings.ratingLoadFailed || 'Status rating tidak dapat dimuat. Coba muat ulang halaman.';
        });
      }
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!settings.nonce || !input || !ratingBox.getAttribute('data-rating-url')) {
          if (status) status.textContent = 'Masuk ke akun untuk memberi rating.';
          return;
        }
        if (status) status.textContent = 'Menyimpan…';
        window.fetch(ratingBox.getAttribute('data-rating-url'), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce },
          body: JSON.stringify({ rating: Number(input.value) })
        }).then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) throw new Error((data && data.message) || strings.ratingFailed || 'Gagal menyimpan rating.');
            return data;
          });
        }).then(function (data) {
          if (average) average.textContent = data.count ? Number(data.average).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '—';
          if (count) count.textContent = Number(data.count || 0).toLocaleString('id-ID');
          if (typeof data.my_rating !== 'undefined') {
            input.value = String(data.my_rating || input.value);
            if (output) { output.value = input.value; output.textContent = input.value; }
          }
          if (status) status.textContent = strings.ratingSaved || 'Rating tersimpan.';
        }).catch(function (error) {
          if (status) status.textContent = error.message || strings.ratingFailed || 'Rating belum berhasil disimpan.';
        });
      });
    });
  }

  function initialize() {
    initializeLocalEngagement();
    initializeRatings();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
