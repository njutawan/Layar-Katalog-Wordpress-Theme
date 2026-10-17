LAYAR KATALOG — TEMA BLOK WORDPRESS
Versi: 1.7.0
Lisensi: GPL-2.0-or-later

Tema blok editorial orisinal untuk katalog film, animasi, serial, rekomendasi, dan ulasan. Tema tidak menyalin logo, aset, teks, kode, atau desain khas situs lain. Tidak ada gambar/video bawaan.

UI memakai layout responsif untuk desktop, tablet, dan ponsel. Pada layar sangat kecil (≤400 px), pencarian header turun ke baris tersendiri agar area ketik lebih lega; tombol sumber player memiliki tinggi minimum 44 px untuk sentuhan.

INSTALASI
1. Unggah dan aktifkan layar-katalog.zip melalui Tampilan > Tema > Tambah Tema > Unggah Tema.
2. Unggah dan aktifkan plugin pendamping layar-katalog-core.zip melalui Plugin > Tambah Plugin > Unggah Plugin.
3. Buka Pengaturan > Permalink, pilih struktur URL yang diinginkan (misalnya Nama Tulisan), lalu klik Simpan Perubahan. Periksa arsip /katalog/, /episode/, dan /genre/ setelah plugin diaktifkan.
4. Buka Tampilan > Editor untuk mengatur identitas, menu, warna, dan template. Menu bawaan menyediakan Beranda, Katalog, dan Episode.
5. Buka Katalog > Genre untuk menambah genre. Saat membuat item Katalog, isi ringkasan, gambar unggulan, genre, format, tahun, status, rating editorial, judul asli, studio/produser, dan durasi bila tersedia. Tandai pilihan editor agar tampil di sorotan beranda.
6. Buka Episode > Tambah Episode. Pilih item induk, isi musim/nomor/durasi, lalu URL langsung MP4/WebM utama dan sumber cadangan yang Anda miliki atau berhak tayangkan.
7. Terbitkan. Item tampil di beranda/arsip; episode muncul di halaman judul, beranda, dan arsip Episode Terbaru.

JELAJAHI & ALUR KONTEN
- Header menyediakan pencarian global. Hasil mencakup pos, halaman, Katalog, dan Episode.
- /katalog/ menyediakan pencarian kata kunci, filter genre/format/tahun/status, urutan, dan pagination.
- /genre/nama-genre/ menampilkan judul berdasarkan genre; tautan genre populer muncul di beranda.
- /episode/ menampilkan episode terbaru dan tautan balik ke item induk.
- Halaman detail judul berisi data editorial, genre, jumlah episode, daftar episode terurut, serta judul terkait.
- Halaman episode berisi player, link ke judul induk, navigasi sebelumnya/berikutnya, dan marker riwayat lokal.
- Detail judul menampilkan rating pengguna terpisah dari rating editorial dan tombol watchlist lokal.
- Arsip Episode menyediakan tombol opt-in notifikasi Web Push. Pengunjung harus mengklik tombol dan menyetujui izin browser.
- Buat halaman WordPress dengan shortcode `[lk_watchlist]` dan `[lk_viewing_history]` agar pengunjung dapat membuka daftar mereka; data ini hanya tersimpan di browser/perangkat tersebut.

PENGATURAN IKLAN
- Buka Tampilan > Iklan untuk mengaktifkan atau menonaktifkan setiap lokasi dan menempelkan kode unit.
- Lokasi yang tersedia: head global, setelah hero beranda, sebelum grid Katalog/Genre, sebelum arsip Episode, sebelum daftar episode pada detail judul, sebelum/sesudah player episode, dan sebelum footer global.
- Lokasi kosong atau nonaktif tidak menghasilkan ruang kosong. Kode loader global hanya dimasukkan sekali di head; unit iklan ditempel pada lokasi konten yang sesuai.
- Setiap slot memiliki pengaturan tinggi minimum area konten (0–1200 px). Isi sesuai ukuran unit penyedia agar membantu mengurangi layout shift; ruang minimum tidak mencegah shift jika materi iklan yang dimuat lebih tinggi.
- Kode JavaScript/iframe hanya dapat disimpan oleh akun dengan hak `unfiltered_html` (umumnya Administrator di WordPress single-site). Akun tanpa hak tersebut hanya dapat menyimpan HTML yang diizinkan WordPress. Gunakan kode dari penyedia tepercaya dan kelola persetujuan cookie/privasi secara terpisah.

KEAMANAN & CONTENT-SECURITY-POLICY
- Tema ini kompatibel dengan mode CSP ketat plugin Core (filter `lkc_csp_strict`). Semua keluaran inline milik tema — blok gaya Customizer, skrip `wp_footer` yang memberi id pada `<main>`, dan kode iklan yang Anda tempel — otomatis diberi nonce permintaan itu, sehingga `'unsafe-inline'` dapat dilepas tanpa merusak tampilan maupun iklan.
- Lebar kolom hero beranda dipindah dari atribut `style="flex-basis:…"` di `templates/front-page.html` ke aturan `.wp-block-column.hero-copy` / `.wp-block-column.hero-side` di `style.css`. Atribut gaya inline tidak dapat membawa nonce, jadi selama masih ada satu saja di template, `style-src` tidak akan pernah bisa lepas dari `'unsafe-inline'`. Tampilan tidak berubah, termasuk penumpukan kolom di layar kecil.
- Konsekuensi mode ketat untuk kode iklan: loader eksternal (`<script src="…">`) tetap harus didaftarkan domainnya lewat filter `lkc_content_security_policy` — snippet-nya disusun otomatis di Alat > Log Keamanan > tab CSP. Skrip yang disuntikkan penyedia iklan pada runtime akan muncul sebagai pelanggaran di tab itu.
- Mode ketat membuat HTML berbeda tiap permintaan (nonce), jadi tidak kompatibel dengan cache halaman penuh kecuali Anda menyediakan nonce stabil lewat filter `lkc_csp_nonce`.

SEO
- Plugin Core mengeluarkan metadata SEO dan sosial, breadcrumb terlihat/JSON-LD, serta schema Website, CreativeWork/Movie/TVSeries, TVEpisode, dan BreadcrumbList.
- Judul/deskripsi SEO custom tersedia untuk Katalog/Episode; bila kosong, konten dan tagline dipakai sebagai fallback.
- Rating tetap skor editorial, bukan `aggregateRating` pengguna.
- Deteksi SEO plugin umum mencegah keluaran ganda; gunakan panel plugin SEO eksternal bila aktif.
- WordPress membuat sitemap di /wp-sitemap.xml (hosting perlu ekstensi PHP SimpleXML). Pastikan situs tidak disetel untuk mencegah indexing sebelum peluncuran.

BATASAN PLAYER & DATA
- Player menggunakan elemen HTML5 dan menerima sampai tiga URL langsung HTTPS berakhiran .mp4 atau .webm. Sejak plugin Core 1.6.0 URL `http://` ditolak (browser memblokirnya sebagai mixed content di situs HTTPS); sumber internal tanpa TLS dapat diizinkan kembali lewat filter `lkc_allow_insecure_media_urls`, dan media dapat dikunci ke host tertentu lewat `lkc_allowed_media_hosts`. Embed/iframe dan kode JavaScript pihak ketiga tidak diterima.
- Gunakan media yang Anda miliki atau berhak tayangkan; periksa lisensi, privasi, bandwidth, hotlink, dan kapasitas hosting.
- Tema dan plugin adalah dua ZIP terpisah. Plugin menyimpan tipe konten dan metadata sehingga data tetap ada saat tema diganti. Sebaiknya buat cadangan dan uji di staging sebelum produksi.
