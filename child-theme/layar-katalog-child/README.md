# Layar Katalog Child Theme

Child theme starter untuk mengkustomisasi tema Layar Katalog tanpa mengubah file tema induk. Perubahan pada tema induk (update) tidak akan menimpa kustomisasi Anda.

## Instalasi

1. Salin folder `layar-katalog-child` ke `wp-content/themes/`.
2. Pastikan tema induk **Layar Katalog** sudah terinstal.
3. Buka **Appearance → Themes** dan aktifkan **Layar Katalog Child**.

## Struktur Folder

```
layar-katalog-child/
├── style.css       ← Header tema + CSS kustom
├── functions.php   ← enqueue parent/child styles + contoh filter
└── README.md       ← File ini
```

## Kustomisasi Umum

### Mengubah Warna

Tambahkan di `style.css`:

```css
:root {
  --lk-lime: #a3e635;   /* warna aksen utama */
  --lk-coral: #f97316;  /* warna aksen sekunder */
}
```

### Mengubah Font Heading

```css
.hero-title,
.section-heading,
.lk-episode-heading h2 {
  font-family: 'Playfair Display', Georgia, serif;
}
```

### Menggunakan Google Fonts

Di `functions.php`, uncomment contoh filter `layarkatalog_google_font_urls`:

```php
function layarkatalog_child_google_fonts( $urls ) {
    $urls[] = 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700';
    return $urls;
}
add_filter( 'layarkatalog_google_font_urls', 'layarkatalog_child_google_fonts' );
```

### Menambah Template

Salin template dari tema induk ke child theme dengan struktur folder yang sama:

```
layar-katalog-child/
├── templates/
│   └── single-lk_title.html   ← Override template detail judul
├── parts/
│   └── header.html             ← Override header
└── patterns/
    └── section-intro.php       ← Override pola
```

WordPress akan memprioritaskan file di child theme.

### Menambah Shortcode / Fungsi

Tambahkan di `functions.php` child theme:

```php
function my_custom_shortcode() {
    return '<p>Konten kustom Anda di sini.</p>';
}
add_shortcode( 'my_shortcode', 'my_custom_shortcode' );
```

### Override Customizer Default

```php
function my_custom_defaults( $default, $setting ) {
    if ( 'layarkatalog_primary_color' === $setting->id ) {
        return '#a3e635';
    }
    return $default;
}
add_filter( 'theme_mod_layarkatalog_primary_color', 'my_custom_defaults', 10, 2 );
```

## Catatan Penting

- **Jangan** mengedit file di `themes/layar-katalog/` (tema induk) — perubahan akan hilang saat update.
- Gunakan **hooks** (action/filter) sebisa mungkin sebelum meng-overwrite template.
- Untuk perubahan besar, salin template dari tema induk ke child theme.
- Plugin **Layar Katalog Core** tetap terpisah dan tidak terpengaruh child theme.

## Lisensi

GPL-2.0-or-later, sama dengan tema induk.