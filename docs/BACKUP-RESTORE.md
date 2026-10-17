# Runbook Backup & Restore — Layar Katalog

Dokumen ini menutup poin 18 dari [`SECURITY-AUDIT.md`](SECURITY-AUDIT.md): cadangan yang tidak pernah diuji restore sama dengan tidak ada cadangan.

Berlaku untuk **Core Plugin 1.6.0 / Theme 1.6.4** ke atas.

---

## 1. Inventaris data yang wajib dicadangkan

### 1.1 Berkas

| Path | Isi | Catatan |
|---|---|---|
| `wp-content/themes/layar-katalog/` | Tema blok (FSE) | Termasuk `templates/`, `parts/`, `patterns/`, `assets/` |
| `wp-content/themes/layar-katalog-child/` | Child theme, bila dipakai | Kustomisasi situs hidup biasanya di sini |
| `wp-content/plugins/layar-katalog-core/` | Plugin pendamping | Termasuk `vendor/` — dependency ikut ter-commit dan tidak diunduh ulang saat restore |
| `wp-content/uploads/` | Gambar unggulan, poster, logo | Tidak ada video di sini bila memakai CDN eksternal |
| `wp-config.php` | **Salt & kunci autentikasi** | Lihat §4.3 — tanpa ini kunci VAPID tidak bisa didekripsi |

### 1.2 Tabel database milik plugin

Semuanya memakai prefix WordPress situs (default `wp_`). **Keempatnya wajib ada di cadangan.**

| Tabel | Isi | Sensitivitas |
|---|---|---|
| `wp_lkc_user_ratings` | Rating akun 1–10 per pengguna per judul | Data pengguna — ikut aturan privasi |
| `wp_lkc_push_subscriptions` | Endpoint push, kunci `p256dh`/`auth`, jumlah kegagalan | **Endpoint push adalah secret per-browser**: siapa pun yang memegangnya dapat mengirim notifikasi palsu ke perangkat itu |
| `wp_lkc_audit_log` | Jejak aksi keamanan (siapa, apa, kapan) | IP & User-Agent sudah berupa HMAC-SHA256, bukan nilai mentah |
| `wp_lkc_csp_reports` | Agregasi pelanggaran Content-Security-Policy | Tidak sensitif |

### 1.3 Opsi (`wp_options`)

| Nama | Isi |
|---|---|
| `lkc_push_options` | Status push, subjek VAPID, public key, dan **private key terenkripsi** (`lkc1:` envelope, AES-256-GCM dengan kunci turunan salt `auth` + `secure_auth`) |
| `layarkatalog_ads` | Kode iklan administrator (global head + per slot) — **kode yang dieksekusi di setiap halaman**, perlakukan seperti kredensial |
| `lkc_rewrite_version`, `lkc_engagement_db_version`, `lkc_security_db_version` | Penanda versi skema; bila hilang, plugin menjalankan ulang `dbDelta` (aman) |
| `theme_mods_<tema-aktif>` | `layarkatalog_primary_color`, `layarkatalog_accent_color`, `layarkatalog_dark_mode`, `layarkatalog_font_family`, `layarkatalog_google_font`, `custom_logo` |

### 1.4 Konten & meta

- Post type `lk_title` dan `lk_episode`, taksonomi `lk_genre` (tabel `wp_posts`, `wp_postmeta`, `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships` — bagian dari cadangan WordPress penuh).
- Meta `_lk_*`: `_lk_format`, `_lk_year`, `_lk_status`, `_lk_rating`, `_lk_featured`, `_lk_original_title`, `_lk_studio`, `_lk_duration`, `_lk_seo_title`, `_lk_seo_description`, `_lk_parent_title`, `_lk_season`, `_lk_episode_number`, `_lk_runtime`, `_lk_video_url`, `_lk_video_url_2`, `_lk_video_url_3` (+ `_label` masing-masing), `_lk_subtitle_url`, `_lk_subtitle_label`.
- Meta operasional push: `_lkc_push_queued_at`, `_lkc_push_sent_at`.

### 1.5 Yang TIDAK perlu dicadangkan

| Item | Alasan |
|---|---|
| Transient `lkc_catalog_years`, cache urutan episode | Dibangun ulang otomatis; hilang tanpa dampak |
| `wp_options` baris `lkc_rl_*` | Penghitung rate limit per pengunjung; basi setelah 60 detik |
| `debug.log` | Berisi galat, bukan data. **Jangan pernah** bisa diakses lewat HTTP |
| Cron `lkc_prune_security_logs`, `lkc_prune_stale_push_subscriptions` | Dijadwalkan ulang otomatis saat `init` |

---

## 2. Membuat cadangan

Contoh minimal di server Linux (sesuaikan nama database/prefix):

```bash
STAMP=$(date +%Y%m%d-%H%M)
DEST=/backup/layarkatalog/$STAMP
mkdir -p "$DEST"

# Database lengkap, termasuk keempat tabel lkc_*
mysqldump --single-transaction --quick --routines --triggers \
  --default-character-set=utf8mb4 \
  -u DBUSER -p DBNAME > "$DEST/db.sql"

# Berkas yang tidak bisa dibangun ulang
tar -czf "$DEST/files.tgz" \
  -C /var/www/html \
  wp-content/themes/layar-katalog \
  wp-content/themes/layar-katalog-child \
  wp-content/plugins/layar-katalog-core \
  wp-content/uploads \
  wp-config.php

# Bukti bahwa dump benar-benar memuat tabel plugin
grep -c "CREATE TABLE .*lkc_" "$DEST/db.sql"   # harus = 4
sha256sum "$DEST/db.sql" "$DEST/files.tgz" > "$DEST/CHECKSUMS"
```

Simpan salinan **di luar server** (objek storage/host lain) dan enkripsi arsipnya: `wp_lkc_push_subscriptions` dan `layarkatalog_ads` adalah materi sensitif.

---

## 3. Memulihkan (restore)

1. **Siapkan lingkungan target** dengan versi WordPress, PHP (≥ 8.2), dan ekstensi (openssl, curl, mbstring, SimpleXML) yang sama atau lebih baru.
2. **Restore database.** Bila prefix target berbeda, sesuaikan nama tabel `lkc_*` dan baris `wp_options` yang memuat nama tabel tersimpan.
   ```bash
   mysql -u DBUSER -p DBNAME < db.sql
   ```
3. **Restore berkas** tema, plugin (termasuk `vendor/`), dan `uploads/`.
4. **Samakan salt.** Lihat §4.3.
5. **Aktifkan plugin** di `Plugin`. Saat aktif, `lkc_activate()` menjalankan `dbDelta` untuk semua tabel plugin dan menjadwalkan cron.
6. **Simpan ulang Permalink** (`Pengaturan → Permalink → Simpan`) agar rute `/katalog/`, `/episode/`, dan `/genre/` terpasang.
7. **Bersihkan cache** server/CDN dan, bila memakai plugin cache halaman, kosongkan seluruhnya.

---

## 4. Titik yang paling sering gagal

### 4.1 `vendor/` tidak ikut tersalin
Plugin ini mengirim dependency Composer di dalam `vendor/` secara sengaja. Bila hilang, `lkc_push_is_configured()` mengembalikan `false` dan push mati tanpa galat yang terlihat. Periksa `plugins/layar-katalog-core/vendor/autoload.php` ada setelah restore.

### 4.2 Tabel `lkc_*` tidak ada
Plugin membuat ulang tabel yang hilang saat `plugins_loaded` (`lkc_maybe_install_security_tables`, `lkc_engagement_maybe_install_tables`), jadi struktur tidak akan hilang — **tetapi isinya tidak bisa dipulihkan dari struktur kosong**. Pastikan dump benar-benar memuat datanya:
```sql
SELECT COUNT(*) FROM wp_lkc_push_subscriptions;
SELECT COUNT(*) FROM wp_lkc_user_ratings;
```

### 4.3 Salt berubah → kunci VAPID tidak terbaca
Private key VAPID disimpan terenkripsi dengan kunci yang diturunkan dari `AUTH_KEY`/`AUTH_SALT`/`SECURE_AUTH_KEY`/`SECURE_AUTH_SALT` di `wp-config.php`. Restore database ke situs dengan salt berbeda membuat envelope tidak bisa didekripsi.

- Gejalanya: notifikasi error di wp-admin — *"kunci privat VAPID yang tersimpan tidak dapat didekripsi"* — dan push berhenti.
- Pemulihannya: buka `Pengaturan → Notifikasi Episode → Buat Kunci VAPID`, lalu minta pengunjung berlangganan ulang (rotasi kunci memang menghapus semua langganan, dan itu dicatat di audit log).
- Pencegahannya: sertakan `wp-config.php` (atau minimal baris salt-nya) dalam cadangan, dan gunakan salt yang sama saat restore.

### 4.4 Kode iklan hilang atau ganda
`layarkatalog_ads` adalah opsi, bukan theme mod. Bila restore hanya menyalin `theme_mods_*`, slot iklan kembali kosong. Setiap perubahan kode iklan tercatat di `wp_lkc_audit_log` sebagai `ad_code_saved` beserta nama slotnya — gunakan itu untuk memverifikasi tidak ada slot yang berubah sendiri setelah restore.

### 4.5 Service worker menyimpan HTML lama
Klien yang sudah memasang service worker dapat menyajikan HTML dari cache lama. Cache versi `lk-*` dibersihkan otomatis saat `activate`, dan HTML yang dipersonalisasi tidak pernah di-cache sejak 1.5.0. Bila konten terlihat basi setelah restore, naikkan `CACHE_NAME` di `plugins/layar-katalog-core/service-worker.js` (mis. `lk-shell-v3`) agar semua klien membuang cache-nya.

---

## 5. Verifikasi setelah restore

Jalankan seluruhnya; centang setiap baris.

- [ ] `Plugin` menampilkan **Layar Katalog Core** aktif, tanpa notice fatal error.
- [ ] `Tema` menampilkan **Layar Katalog** aktif; beranda, `/katalog/`, `/episode/`, dan satu halaman genre tampil normal.
- [ ] Satu item Katalog dan satu Episode terbuka; player memutar sumber MP4/WebM.
- [ ] Pencarian REST bekerja: `GET /wp-json/lkc/v1/catalog/search?search=<kata>` mengembalikan `200` dengan `items`.
- [ ] Header keamanan terkirim: `curl -sI https://<situs>/ | grep -iE 'x-content-type-options|referrer-policy|content-security-policy-report-only|x-frame-options'` → keempatnya ada.
- [ ] Kolektor CSP hidup: `curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/csp-report' --data '{"csp-report":{"document-uri":"https://<situs>/","violated-directive":"script-src","blocked-uri":"https://example.net/x.js"}}' https://<situs>/wp-json/lkc/v1/csp-report` → `204`, dan barisnya muncul di `Alat → Log Keamanan → CSP`.
- [ ] `Alat → Log Keamanan → Audit` dapat dibuka dan menampilkan riwayat (bila ada).
- [ ] Push: status di `Pengaturan → Notifikasi Episode` menunjukkan kunci tersimpan **tanpa** notice dekripsi; tombol `[lkc_push_toggle]` meminta izin dan langganan baru muncul di `wp_lkc_push_subscriptions`.
- [ ] Rate limiter tidak salah sasaran: 10 permintaan halaman beruntun tidak menghasilkan `429`. Bila ya, periksa filter `lkc_rate_limit_ip` untuk proxy/CDN.
- [ ] `directori vendor` tidak bisa dibaca dari web: `curl -s -o /dev/null -w '%{http_code}' https://<situs>/wp-content/plugins/layar-katalog-core/vendor/composer/installed.json` → **bukan** `200`.
- [ ] Cron terjadwal: `wp cron event list` memuat `lkc_prune_security_logs` dan `lkc_prune_stale_push_subscriptions`.
- [ ] `WP_DEBUG_DISPLAY` mati dan `debug.log` tidak bisa dijangkau lewat HTTP.

---

## 6. Latihan restore kuartalan

Lakukan setiap kuartal di lingkungan staging (bukan produksi). Satu latihan = satu baris di log ini.

| Tanggal | Versi plugin/tema | Sumber cadangan | Durasi restore | Hasil verifikasi (§5) | Catatan / tindakan lanjut | Operator |
|---|---|---|---|---|---|---|
| | | | | ☐ lulus ☐ gagal | | |
| | | | | ☐ lulus ☐ gagal | | |
| | | | | ☐ lulus ☐ gagal | | |
| | | | | ☐ lulus ☐ gagal | | |

Aturan latihan:

1. Pakai cadangan **terbaru yang dibuat otomatis**, bukan cadangan yang disiapkan khusus untuk latihan.
2. Ukur waktunya. Bila restore memakan lebih lama daripada target pemulihan yang disepakati, itu temuan — catat dan perbaiki.
3. Uji minimal satu kali setahun dengan **server kosong** (bukan hanya database), supaya langkah berkas dan salt ikut teruji.
4. Setiap kegagalan latihan menghasilkan isu yang harus ditutup sebelum latihan berikutnya.

### 6.1 Bagian mekanisnya sudah diotomatiskan

`tests/wp/rehearse-restore.sh` menjalankan dump → restore ke database sementara → verifikasi inventaris §1 → pemeriksaan salt §4.3, lalu mencetak baris log untuk tabel di atas. Skrip ini **tidak pernah** menulis ke database produksi.

```bash
# dari root WordPress di staging
bash tests/wp/rehearse-restore.sh \
  --target-db=lkc_drill_2026q4 \
  --yes-drop=lkc_drill_2026q4 \
  --restored-wp-config=/path/ke/cadangan/wp-config.php
```

Yang diperiksa otomatis: keempat tabel `lkc_*` ada dan bisa dibaca, jumlah judul/episode/URL video, opsi `lkc_push_options` dan `layarkatalog_ads`, theme mods, hook cron pruning di dalam opsi `cron`, envelope VAPID hasil restore identik byte-per-byte dengan yang live, kunci itu masih bisa didekripsi dengan salt yang berlaku, dan kedelapan baris salt di `wp-config.php` cadangan sama dengan yang live.

Pengaman: nama database tujuan harus disebut dua kali (`--target-db` dan `--yes-drop`), nama yang memuat karakter shell ditolak, dan permintaan untuk restore ke database produksi dihentikan sebelum satu perintah `mysql` pun dijalankan. `tests/wp/guards.test.sh` menguji keempat penolakan itu di CI dengan `wp` dan `mysql` palsu.

Yang **tetap manual** setelah skrip ini lulus — dan justru alasan latihan itu ada: memuat hasil restore di browser, membuka satu judul, satu episode, dan satu pencarian; memastikan video benar-benar diputar dan subtitle muncul; lalu mengirim satu notifikasi uji dan memastikan notifikasi itu benar-benar tiba di perangkat.
