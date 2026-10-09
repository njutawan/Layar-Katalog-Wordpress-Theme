LAYAR KATALOG CORE
Versi 1.4.0
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

VALIDASI & KOMPATIBILITAS
- Format/status dibatasi ke pilihan tersedia; tahun 1888–2100; rating 0–10; musim 1–999; nomor episode 0–9999 (presisi 0,1); durasi 0–999 menit.
- URL video disanitasi ketat dan hanya menerima HTTP/HTTPS MP4/WebM. Pertimbangkan lisensi, privasi, bandwidth, hotlink, dan kapasitas hosting.
- Data CPT dan metadata disimpan terpisah dari tema agar tetap ada saat tema diganti. Jangan nonaktifkan plugin jika URL arsip Katalog/Episode dan taksonomi Genre tetap diperlukan.
