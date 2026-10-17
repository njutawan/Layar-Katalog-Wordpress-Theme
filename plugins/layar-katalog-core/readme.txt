LAYAR KATALOG CORE
Versi 1.7.0
Lisensi: GPL-2.0-or-later

Plugin pendamping tema Layar Katalog. Menyediakan tipe konten Katalog dan Episode, taksonomi Genre, filter/pencarian, metadata editorial/SEO, watchlist/riwayat lokal, rating pengguna, notifikasi Web Push VAPID, serta player HTML5 multi-sumber.

PERSYARATAN TEKNIS
- PHP 8.2+ dan WordPress 6.3+. Paket menyertakan library Web Push beserta Composer dependencies.
- Untuk VAPID push: HTTPS, ekstensi PHP OpenSSL/cURL/mbstring, dan WP-Cron yang berjalan. Notifikasi dikirim ke semua browser yang secara eksplisit berlangganan.
- Kunci privat VAPID disimpan di database WordPress. Batasi akses administrator dan lindungi cadangan database. Jangan menaruh kunci privat ke tema/ZIP atau membagikannya.

INSTALASI
1. Unggah dan aktifkan layar-katalog-core.zip melalui Plugin > Tambah Plugin > Unggah Plugin.
2. Pastikan tema Layar Katalog aktif.
3. Buka Pengaturan > Permalink, pilih struktur URL yang diinginkan (misalnya Nama Tulisan), lalu klik Simpan Perubahan. Plugin juga memperbarui rewrite rule ketika diaktifkan.
4. Buka Katalog > Genre untuk membuat genre. Genre yang terpakai akan muncul di beranda, halaman katalog, dan filter.
5. Buka Katalog > Tambah Item. Isi judul, ringkasan, gambar unggulan, genre, format, tahun, status, rating editorial, judul asli, studio/produser, durasi, dan SEO opsional. Centang pilihan editor untuk menampilkan judul di sorotan beranda.
6. Buka Episode > Tambah Episode. Pilih judul induk, isi musim/nomor/durasi dan URL video langsung MP4/WebM. Maksimal tiga sumber video langsung dapat dipilih pengunjung.
7. Terbitkan. Episode akan muncul pada daftar judul induk, halaman Episode Terbaru, beranda, pencarian, dan tautan sebelumnya/berikutnya.

JELAJAHI & CARI
- /katalog/ menampilkan katalog dengan pencarian kata kunci dan filter genre, format, tahun, status, serta urutan terbaru/terlama/rating editorial/judul A–Z.
- /genre/nama-genre/ adalah arsip genre. Filter dari arsip genre akan membawa pilihan genre ke katalog.
- /episode/ menampilkan episode terbaru dengan tautan ke judul induk.
- Pencarian WordPress mencakup pos, halaman, Katalog, dan Episode.
- Genre adalah taksonomi publik `lk_genre`, tersedia di REST API dan dapat dipakai untuk navigasi.

DATA & ALUR EPISODE
- Item Katalog dapat memuat judul asli, format, tahun, status, rating editorial, studio/produser, durasi, genre, ringkasan, dan jumlah episode terbit.
- Setiap Episode terkait ke satu judul induk, dengan musim, nomor episode, durasi, dan hingga tiga sumber video.
- Halaman judul menampilkan daftar episode terurut dan rekomendasi judul yang berbagi genre.
- Halaman episode menampilkan induk, player, serta navigasi episode sebelumnya/berikutnya dan kembali ke judul induk.
- Sumber player hanya menerima URL langsung HTTP/HTTPS berekstensi .mp4 atau .webm. Iframe/embed pihak ketiga tidak diterima; gunakan media yang Anda miliki atau berhak tayangkan.

KETERLIBATAN PENGUNJUNG
- `[lk_watchlist_button]` dan `[lk_watchlist]` menyediakan daftar simpanan di browser/perangkat ini saja; tidak memakai akun dan tidak disinkronkan. Data lokal dapat hilang bila storage browser dibersihkan.
- `[lk_episode_history_marker]` merekam halaman episode yang dibuka; `[lk_viewing_history]` menampilkan 50 episode terakhir di browser/perangkat yang sama.
- `[lk_user_rating]` menyimpan satu rating 1–10 per akun WordPress per judul. Nilai dan rata-ratanya terpisah dari rating editorial. Pengguna harus masuk; rating dihapus saat akun dihapus.
- Di Pengaturan > Notifikasi Episode, buat kunci VAPID, isi subjek kontak, lalu aktifkan push. Tombol persetujuan tersedia dengan `[lkc_push_toggle]` dan telah ditempatkan di arsip Episode. Push hanya berjalan setelah pengunjung mengklik tombol dan menyetujui izin browser.
- Notifikasi bersifat broadcast ke semua pelanggan aktif setiap episode baru terbit; watchlist tidak dikirim ke server. Pengunjung dapat berhenti berlangganan dari tombol yang sama. Endpoint push dibatasi ke layanan push browser publik yang dikenal.

PERFORMA
- Daftar tahun filter katalog di-cache enam jam dan diperbarui saat item katalog disimpan atau dihapus.
- Urutan ID episode per judul di-cache 12 jam dan dibatalkan saat episode disimpan atau dihapus, agar daftar dan navigasi episode dapat memakai urutan yang sama pada kunjungan berulang.
- Halaman judul masih menampilkan semua episode. Untuk seri dengan ratusan atau ribuan episode, ukur di staging dan pertimbangkan pagination/pengelompokan agar HTML awal tetap ringkas.

SEO YANG DISEDIAKAN
- Judul SEO dan deskripsi SEO opsional untuk Katalog dan Episode; fallback otomatis dari judul/excerpt/konten/tagline.
- Meta description, Open Graph, Twitter Cards, canonical arsip, breadcrumb HTML/JSON-LD, dan schema Website, CreativeWork/Movie/TVSeries, TVEpisode, serta BreadcrumbList.
- Rating adalah skor editorial dan sengaja tidak ditandai sebagai `aggregateRating`.
- Jika Yoast SEO, Rank Math, AIOSEO, SEOPress, The SEO Framework, atau Jetpack SEO terdeteksi, metadata/schema bawaan dimatikan agar tidak duplikat. Untuk plugin lain, tambahkan `add_filter( 'lkc_has_external_seo_plugin', '__return_true' );` di plugin kustom/child theme.
- Sitemap inti WordPress tersedia di /wp-sitemap.xml dan mencakup Katalog/Episode. Hosting perlu ekstensi PHP SimpleXML.

BLOK DINAMIS
- layar-katalog/breadcrumbs
- layar-katalog/catalog-details
- layar-katalog/episode-list
- layar-katalog/episode-info
- layar-katalog/video-player
- layar-katalog/featured-title
- layar-katalog/catalog-filters
- layar-katalog/genre-links
- layar-katalog/search-result-details
- layar-katalog/related-titles
- layar-katalog/episode-navigation

SHORTCODE (opsional)
[lk_breadcrumbs id="123"] — jejak navigasi Katalog/Episode.
[lk_catalog_details id="123"] — metadata item katalog.
[lk_episode_list id="123"] — daftar episode terurut.
[lk_episode_info id="123"] — musim, nomor, durasi, dan tautan induk.
[lk_episode_navigation id="123"] — navigasi episode sebelumnya/berikutnya.
[lk_player id="123"] — player HTML5 multi-sumber.
[lk_featured_title] — sorotan pilihan editor, fallback ke item terbaru.
[lk_catalog_filters] — form filter katalog.
[lk_genre_links limit="12"] — tautan genre populer.
[lk_related_titles id="123"] — judul dengan genre terkait.
[lk_user_rating id="123"] — rata-rata dan form rating pengguna login.
[lk_watchlist_button id="123"] — tombol simpan/hapus dari watchlist lokal.
[lk_watchlist] — daftar watchlist lokal; tempatkan pada halaman WordPress.
[lk_viewing_history] — riwayat episode lokal; tempatkan pada halaman WordPress.
[lk_episode_history_marker] — marker untuk merekam episode yang sedang dibuka.
[lkc_push_toggle] — tombol opt-in/opt-out Web Push.

KEAMANAN & AUDIT
- Semua route REST `lkc/v1` dibatasi per pengunjung: 60 permintaan baca dan 10 permintaan tulis per menit (429 + Retry-After). Ubah dengan filter `lkc_rest_rate_limits`. Di belakang Cloudflare/proxy, WAJIB petakan IP asli lewat filter `lkc_rate_limit_ip`; jika tidak, seluruh pengunjung akan masuk ke satu bucket.
- CORS hanya mengizinkan origin situs sendiri. Origin lain tidak menerima `Access-Control-Allow-*` sama sekali, sehingga JavaScript lintas situs tidak dapat membaca respons yang membawa cookie. Tambahkan origin staging/aplikasi lewat filter `lkc_allowed_cors_origins`; jangan pernah memakai `*`.
- Body permintaan tulis dibatasi 8 KB (filter `lkc_rest_max_body_bytes`) dan dijawab 413 bila melebihi. Batas otoritatif tetap di web server (`client_max_body_size`/`LimitRequestBody`).
- Content-Security-Policy dikirim dalam mode report-only: tidak memblokir apa pun, hanya mengumpulkan pelanggaran ke route anonim `POST lkc/v1/csp-report`. Laporan diagregasi per (direktif, sumber terblokir, path dokumen) dan berhenti menambah baris pada 500 sidik jari (filter `lkc_csp_report_max_rows`).
- Untuk membaca pelanggaran: buka Alat > Log Keamanan, tab CSP. Setelah domain iklan/analytics/font didaftarkan lewat filter `lkc_content_security_policy`, aktifkan pemblokiran dengan filter `lkc_csp_mode` (`'enforce'`), atau matikan dengan `'off'`.
- Tab CSP juga menyusun snippet filter itu untuk Anda dari laporan yang tersimpan: host yang benar-benar terblokir beserta jumlahnya, host yang sudah diizinkan kebijakan dilewati, dan kata kunci `inline`/`eval`/`data` dijelaskan sebagai tindakan (bukan ditawarkan sebagai domain, karena ketiganya memang bukan domain).
- `'unsafe-inline'` masih dipakai secara default karena kode iklan administrator dicetak ke `wp_head` dan WordPress core mencetak gaya/skrip inline sendiri. Untuk melepasnya, kembalikan true pada filter `lkc_csp_strict`: `'unsafe-inline'` dan `'unsafe-eval'` dibuang dan diganti nonce per-request yang distempel ke semua elemen inline, termasuk milik core (lewat `wp_script_attributes`/`wp_style_attributes`) dan kode iklan. Nyalakan dulu sambil tetap `report-only`, jelajahi situs, baca tab CSP, baru pindah ke `'enforce'`.
- Mode ketat TIDAK kompatibel dengan cache halaman penuh: nonce berbeda tiap permintaan, jadi HTML yang disimpan cache akan merusak skrip pengunjung berikutnya. Karena itu setiap respons front-end ditandai `X-LK-Personalized: 1` saat mode ketat aktif (service worker bawaan sudah menolak menyimpannya). Bila Anda butuh cache HTML, sediakan nonce stabil lewat filter `lkc_csp_nonce`.
- Plugin pihak ketiga yang mencetak `<script>` mentah tanpa melewati API skrip WordPress tidak akan mendapat nonce dan akan diblokir pada mode ketat. Itulah gunanya fase `report-only`.
- Header keamanan lain: `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy`, dan HSTS saat HTTPS. Semua dapat diubah/dinonaktifkan lewat filter `lkc_security_headers`.
- Tab Audit pada Alat > Log Keamanan mencatat perubahan pengaturan push, rotasi kunci VAPID, penghapusan langganan, langganan anonim yang ditolak, dan perubahan kode iklan (nama slot saja, bukan isi kodenya). Aksi yang dicatat dikontrol filter `lkc_audit_log_actions`.
- IP dan User-Agent disimpan sebagai HMAC-SHA256 dengan salt auth WordPress, bukan nilai mentah. Cron harian menghapus entri audit setelah 90 hari dan laporan pelanggaran yang tidak terlihat lagi setelah 30 hari (filter `lkc_audit_log_retention_days`, `lkc_csp_report_retention_days`).
- Service worker hanya menyimpan respons same-origin yang berhasil, bukan `no-store`/`private`, dan tidak pernah menyimpan HTML yang ditandai header `X-LK-Personalized` (pengunjung login/pratinjau). Cache lama dibersihkan otomatis saat pembaruan.
- Kunci privat VAPID disimpan terenkripsi (AES-256-GCM) dengan kunci turunan salt `auth`/`secure_auth` di `wp-config.php`, bukan teks mentah. Konsekuensinya: cadangan database WAJIB disertai `wp-config.php`. Bila salt berubah, muncul notifikasi di wp-admin dan kunci harus dibuat ulang di Pengaturan > Notifikasi Episode (langganan perlu opt-in ulang). Kunci lama dimigrasikan otomatis.
- URL video dan subtitle hanya menerima HTTPS. Sumber internal tanpa TLS dapat diizinkan kembali lewat filter `lkc_allow_insecure_media_urls`, dan media dapat dikunci ke host tertentu lewat `lkc_allowed_media_hosts` (subdomain ikut diizinkan, prefiks mirip seperti `notcdn.example.com` tidak).
- Langganan push yang terus gagal dikirim dihitung (`failure_count`, `last_failure_at`) dan dihapus cron harian setelah 5 kali gagal atau 30 hari gagal terus-menerus (filter `lkc_push_max_failures`, `lkc_push_stale_days`). Langganan yang belum pernah gagal tidak pernah dihapus.
- Direktori `vendor/` ditolak untuk akses web lewat `.htaccess`. Pengguna nginx WAJIB menambahkan blok `location` yang tercantum di dalam berkas itu, karena nginx mengabaikan `.htaccess`. Setiap subdirektori plugin/tema diberi `index.php` agar isi direktori tidak dapat dilist.
- Tabel plugin: `wp_lkc_push_subscriptions`, `wp_lkc_user_ratings`, `wp_lkc_audit_log`, `wp_lkc_csp_reports`. Keempatnya harus ikut dalam cadangan database. Lihat `docs/BACKUP-RESTORE.md` untuk runbook lengkap.

PENGUJIAN
- Suite uji tersedia di `tests/` dan dijalankan GitHub Actions pada setiap push/PR: `php -l` untuk semua berkas PHP, `node --check` untuk semua berkas JavaScript, 254 assertion PHP atas logika keamanan, 18 assertion aturan cache service worker, 22 assertion DOM player, 22 assertion komparator standar kode, 24 assertion pengaman skrip staging, dan `composer audit`.
- Job PHPCS/WPCS bersifat BLOCKING atas pelanggaran baru: laporan revisi ini dibandingkan dengan laporan revisi basisnya, dan hanya pelanggaran yang ditambahkan perubahan itu yang menggagalkan build. Utang gaya kode lama tidak diwariskan ke perubahan baru, dan pelanggaran yang diperbaiki ikut dilaporkan.
- Suite PHP dapat dijalankan tanpa PHP terpasang lewat `node tests/php/run-phpwasm.mjs 8.2` (PHP 8.2 asli dalam WebAssembly).
- Suite JavaScript menerima `--previous` untuk membandingkan dengan versi di `git HEAD`, sehingga perbaikan tetap dapat dibuktikan, bukan hanya diklaim.
- Dua hal yang tidak bisa diuji unit karena butuh WordPress sungguhan punya skripnya: `bash tests/wp/smoke-activate.sh` (uji aktivasi di staging — tabel, route, cron, header, kunci VAPID terenkripsi yang masih terbaca, `vendor/` tidak terjangkau web, rate limiter 429) dan `bash tests/wp/rehearse-restore.sh --target-db=NAMA --yes-drop=NAMA` (latihan restore kuartalan ke database sementara; tidak pernah menulis ke database produksi). Lihat `tests/README.md`.

VALIDASI & KOMPATIBILITAS
- Format/status dibatasi ke pilihan tersedia; tahun 1888–2100; rating 0–10; musim 1–999; nomor episode 0–9999 (presisi 0,1); durasi 0–999 menit.
- URL video disanitasi ketat dan hanya menerima HTTP/HTTPS MP4/WebM. Pertimbangkan lisensi, privasi, bandwidth, hotlink, dan kapasitas hosting.
- Data CPT dan metadata disimpan terpisah dari tema agar tetap ada saat tema diganti. Jangan nonaktifkan plugin jika URL arsip Katalog/Episode dan taksonomi Genre tetap diperlukan.
