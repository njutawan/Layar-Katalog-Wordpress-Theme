# Pemeriksaan responsif UI — Layar Katalog

**Tanggal:** 8 Oktober 2026  
**Tema yang diperiksa:** 1.6.1  
**Plugin Core:** 1.4.0

## Cakupan dan metode

Pemeriksaan ini membaca stylesheet dan template aktual, lalu memetakan aturan media query yang aktif pada viewport 360, 390, 768, dan 1440 CSS px. Ini **audit source-level**, bukan screenshot atau pengujian interaktif di browser; stylesheet juga diparse tanpa error. Lingkungan kerja ini tidak memiliki browser headless maupun instalasi WordPress untuk merender halaman.

## Hasil menurut viewport

| Viewport | Aturan yang aktif | Penilaian source-level |
|---|---|---|
| **360 px** | Aturan ≤400, ≤560, ≤782, dan ≤1100 px | Header membungkus pencarian ke baris penuh di bawah logo/menu. Filter memiliki dua kolom, dengan kolom pencarian dan aksi selebar panel. Kartu katalog dua kolom; daftar episode satu kolom. Video selebar kontainer; pilihan sumber memiliki tinggi sentuh 44 px. |
| **390 px** | Aturan ≤400, ≤560, ≤782, dan ≤1100 px | Sama dengan 360 px, dengan tambahan lebar untuk konten. Pencarian tetap berada di baris tersendiri agar ruang input tidak berebut dengan logo/menu. |
| **768 px** | Aturan ≤782 dan ≤1100 px | Header memakai susunan tablet; katalog dua kolom, daftar episode dua kolom, filter tiga kolom, pencarian filter mengambil dua kolom. Video tetap responsif. |
| **1440 px** | Tidak ada breakpoint sempit yang aktif | Katalog empat kolom, daftar episode tiga kolom, enam field filter dan aksi tersusun dalam tujuh track satu baris, pencarian header memakai basis 205 px, video mengikuti lebar konten. |

## Perubahan hasil tindak lanjut

- Pada viewport **≤400 px**, pencarian header dipindah ke baris tersendiri dan mengambil lebar yang tersedia; tombol pencariannya 40×40 px.
- Tombol pemilih sumber video dinaikkan dari tinggi minimum 38 px menjadi **44 px**, disejajarkan di tengah, dan dapat membungkus ke baris berikutnya.
- Tema dinaikkan ke **1.6.1**. Tema tetap menggunakan desain orisinal Layar Katalog.

## Batas verifikasi

Breakpoint dan deklarasi layout di atas sudah diperiksa di source; **belum** ada pengukuran bounding box, screenshot, tes sentuh, atau pemeriksaan scroll horizontal pada browser nyata. Karena itu laporan ini tidak menyatakan hasil visual final “lulus” pada semua perangkat. Provider iklan atau CSS tambahan dari plugin lain juga dapat memengaruhi hasil di situs terpasang.