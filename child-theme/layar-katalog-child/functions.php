<?php
/**
 * Layar Katalog Child Theme — functions.php
 *
 * File ini dimuat SEBELUM functions.php tema induk.
 * Gunakan untuk menambahkan fungsi kustom tanpa mengubah file tema induk.
 *
 * @package Layar_Katalog_Child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue stylesheet tema induk + child.
 */
function layarkatalog_child_enqueue_styles() {
	/* Style tema induk. */
	wp_enqueue_style(
		'layar-katalog-parent',
		get_template_directory_uri() . '/style.css',
		array(),
		wp_get_theme( 'layar-katalog' )->get( 'Version' )
	);

	/* Style child theme. */
	wp_enqueue_style(
		'layar-katalog-child',
		get_stylesheet_directory_uri() . '/style.css',
		array( 'layar-katalog-parent' ),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'layarkatalog_child_enqueue_styles' );

/*
 * ── CONTOH: Override Customizer default values ──
 *
 * function layarkatalog_child_customizer_defaults( $default, $setting ) {
 *     if ( 'layarkatalog_primary_color' === $setting->id ) {
 *         return '#a3e635';
 *     }
 *     return $default;
 * }
 * add_filter( 'theme_mod_layarkatalog_primary_color', 'layarkatalog_child_customizer_defaults', 10, 2 );
 */

/*
 * ── CONTOH: Tambahkan font Google kustom ──
 *
 * function layarkatalog_child_google_fonts( $urls ) {
 *     $urls[] = 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700';
 *     return $urls;
 * }
 * add_filter( 'layarkatalog_google_font_urls', 'layarkatalog_child_google_fonts' );
 */

/*
 * ── CONTOH: Tambahkan shortcode kustom ──
 *
 * function layarkatalog_child_custom_greeting() {
 *     return '<p class="custom-greeting">Selamat datang di katalog kami!</p>';
 * }
 * add_shortcode( 'lk_greeting', 'layarkatalog_child_custom_greeting' );
 */