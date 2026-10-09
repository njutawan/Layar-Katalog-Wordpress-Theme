# Layar Katalog for WordPress

An original Full Site Editing block theme and companion plugin for editorial film, animation, and series catalogs. The project includes catalog/episode content types, a native HTML5 player, local engagement features, and optional self-hosted Web Push.

**Tema / Theme:** 1.6.1 · **Plugin Core:** 1.4.0 · **License:** GPL-2.0-or-later

> The theme's design and assets are original to Layar Katalog. No film/video media is bundled. Add only media you own or are authorized to publish.

## Features

- Catalog and episode post types, genre taxonomy, metadata, archive filters, search, episode navigation, and related-title blocks.
- Responsive block theme for catalog, episode, search, and editorial pages; theme settings include configurable advertising slots.
- HTML5 video player for direct MP4/WebM sources only.
- Browser-local watchlist and episode-page history; no account required and no cross-device sync.
- Account-based user ratings, one rating per WordPress account per title, on a 1–10 scale; kept separate from editorial ratings.
- Optional VAPID Web Push: each new published episode is broadcast to every active, explicitly opted-in browser subscription, independently of watchlists.

## Requirements

- WordPress 6.3 or newer.
- PHP 8.2 or newer to use the companion plugin (the plugin bundles its Composer dependencies under `vendor/`).
- OpenSSL, cURL, and mbstring for Web Push; HTTPS and working WP-Cron are required for push delivery.
- GMP or BCMath is optional and can speed up cryptographic calculations.

The plugin ZIP/source already contains its Composer runtime dependencies; a production WordPress host does not need Composer. Developers changing plugin dependencies should use the checked-in `composer.json` and `composer.lock`.

## Install from this repository

Copy the two directories into the matching WordPress content directories:

```sh
cp -R plugins/layar-katalog-core /path/to/wordpress/wp-content/plugins/
cp -R themes/layar-katalog /path/to/wordpress/wp-content/themes/
```

Then activate **Layar Katalog Core** and the **Layar Katalog** theme from WordPress Admin. Save **Settings → Permalinks** once after activation. Add your own genres, catalog titles, episodes, and featured images.

To use the browser-local pages, create WordPress pages containing `[lk_watchlist]` and/or `[lk_viewing_history]`. The episode archive includes the push opt-in control after an administrator configures VAPID in **Settings → Notifikasi Episode**. Never commit real VAPID private keys or production subscription data to this repository.

## Privacy notes

- Watchlist and viewing-history records stay in that browser's local storage and can be removed by clearing the site's browser data.
- Ratings are associated with a WordPress account and title; the plugin deletes them when the account or title is deleted.
- Push endpoint and encryption-key data are stored by the site only after a visitor opts in. Visitors can unsubscribe through the control or revoke browser permission. New-episode notifications are broadcast to all active subscriptions, not targeted from the watchlist.

## Validation status

Source syntax checks, focused isolated smoke tests, Composer advisory audit, and package integrity checks have been run. A mocked push send exercised signing/encryption without contacting an external push service. Full WordPress activation/migration, cross-browser visual testing, and live HTTPS/WP-Cron/push-provider delivery still need to be tested on a staging site. See [`docs/RESPONSIVE-CHECK.md`](docs/RESPONSIVE-CHECK.md) for the source-level viewport review.

## Contributing

Issues and pull requests are welcome. Please avoid submitting copyrighted media, live VAPID keys, database exports, personal data, or credentials. See [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).

## Lisensi

Kode proyek dilisensikan di bawah **GNU GPL versi 2 atau versi yang lebih baru (GPL-2.0-or-later)**. Komponen Composer pihak ketiga memiliki lisensi masing-masing; lihat `THIRD-PARTY-NOTICES.md` dan file lisensi di dalam `plugins/layar-katalog-core/vendor/`.

## Bahasa Indonesia — ringkasan

Layar Katalog adalah tema blok orisinal beserta plugin pendamping untuk katalog film, animasi, dan serial. Plugin menyediakan katalog/episode, genre, pencarian dan filter, player HTML5 MP4/WebM, rating akun 1–10, watchlist/riwayat lokal, serta notifikasi Web Push VAPID opsional. Watchlist/riwayat tersimpan di browser; push dikirim ke semua browser yang sudah opt-in, bukan berdasarkan watchlist.

Persyaratan gabungan: WordPress 6.3+, PHP 8.2+, dan HTTPS/WP-Cron untuk Web Push. Salin `themes/layar-katalog` ke `wp-content/themes/` serta `plugins/layar-katalog-core` ke `wp-content/plugins/`, aktifkan keduanya, lalu simpan ulang Permalink. Lihat dokumentasi plugin untuk pengaturan rinci. Proyek berlisensi GPL-2.0-or-later.