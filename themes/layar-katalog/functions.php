<?php
/**
 * Theme setup for Layar Katalog.
 *
 * @package Layar_Katalog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function layarkatalog_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support(
		'custom-logo',
		array(
			'height'               => 120,
			'width'                => 120,
			'flex-height'          => true,
			'flex-width'           => true,
			'header-text'          => array( 'site-title', 'site-tagline' ),
			'unlink-homepage-logo' => false,
		)
	);
	add_editor_style( 'style.css' );
	load_theme_textdomain( 'layar-katalog', get_template_directory() . '/languages' );
	register_sidebar(
		array(
			'name'          => __( 'Footer Widget 1', 'layar-katalog' ),
			'id'            => 'footer-1',
			'description'   => __( 'Widget di kolom pertama footer.', 'layar-katalog' ),
			'before_widget' => '<div id="%1$s" class="widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		)
	);
	register_sidebar(
		array(
			'name'          => __( 'Footer Widget 2', 'layar-katalog' ),
			'id'            => 'footer-2',
			'description'   => __( 'Widget di kolom kedua footer.', 'layar-katalog' ),
			'before_widget' => '<div id="%1$s" class="widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		)
	);
	register_sidebar(
		array(
			'name'          => __( 'Footer Widget 3', 'layar-katalog' ),
			'id'            => 'footer-3',
			'description'   => __( 'Widget di kolom ketiga footer.', 'layar-katalog' ),
			'before_widget' => '<div id="%1$s" class="widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		)
	);
}

function layarkatalog_skip_link() {
	echo '<a class="skip-link screen-reader-text" href="#main-content">' . esc_html__( 'Langsung ke konten utama', 'layar-katalog' ) . '</a>' . "\n";
}
add_action( 'wp_body_open', 'layarkatalog_skip_link' );

/**
 * Render the custom logo inside the block header template.
 * Shows nothing when no custom logo is set, so the default brand-mark is visible.
 */
function layarkatalog_custom_logo_shortcode() {
	if ( ! has_custom_logo() ) {
		return '';
	}
	$logo_id   = get_theme_mod( 'custom_logo' );
	$logo_url  = wp_get_attachment_image_url( $logo_id, 'full' );
	$logo_attr = array(
		'class'   => 'brand-custom-logo',
		'alt'     => get_bloginfo( 'name' ),
		'loading' => 'eager',
		'width'   => 43,
		'height'  => 43,
	);
	$img = '<a href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( get_bloginfo( 'name' ) ) . '" class="brand-custom-logo-link">' . wp_get_attachment_image( $logo_id, 'thumbnail', false, $logo_attr ) . '</a>';
	return '<div class="brand-custom-logo-wrap">' . $img . '</div>';
}
add_shortcode( 'layarkatalog_custom_logo', 'layarkatalog_custom_logo_shortcode' );

/**
 * Add an id to the first <main> element so the skip link has a target.
 * Runs once on wp_footer to avoid dependencies on template markup.
 */
function layarkatalog_ensure_main_content_id() {
	?>
	<script>
	(function () {
		'use strict';
		var main = document.querySelector( 'main' );
		if ( main && ! main.id ) {
			main.id = 'main-content';
		}
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'layarkatalog_ensure_main_content_id', 1 );

/** Register Customizer options for appearance control without editing code. */
function layarkatalog_customize_register( $wp_customize ) {
	/* ── Section ── */
	$wp_customize->add_section(
		'layarkatalog_appearance',
		array(
			'title'    => __( 'Layar Katalog', 'layar-katalog' ),
			'priority' => 35,
		)
	);

	/* ── Primary color ── */
	$wp_customize->add_setting(
		'layarkatalog_primary_color',
		array(
			'default'           => '#ccf36b',
			'sanitize_callback' => 'sanitize_hex_color',
			'transport'         => 'postMessage',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'layarkatalog_primary_color',
			array(
				'label'   => __( 'Warna aksen utama', 'layar-katalog' ),
				'section' => 'layarkatalog_appearance',
			)
		)
	);

	/* ── Accent (coral) color ── */
	$wp_customize->add_setting(
		'layarkatalog_accent_color',
		array(
			'default'           => '#fa765d',
			'sanitize_callback' => 'sanitize_hex_color',
			'transport'         => 'postMessage',
		)
	);
	$wp_customize->add_control(
		new WP_Customize_Color_Control(
			$wp_customize,
			'layarkatalog_accent_color',
			array(
				'label'   => __( 'Warna aksen sekunder', 'layar-katalog' ),
				'section' => 'layarkatalog_appearance',
			)
		)
	);

	/* ── Dark mode ── */
	$wp_customize->add_setting(
		'layarkatalog_dark_mode',
		array(
			'default'           => 'auto',
			'sanitize_callback' => function ( $value ) {
				return in_array( $value, array( 'auto', 'light', 'dark' ), true ) ? $value : 'auto';
			},
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'layarkatalog_dark_mode',
		array(
			'label'   => __( 'Mode gelap', 'layar-katalog' ),
			'section' => 'layarkatalog_appearance',
			'type'    => 'radio',
			'choices' => array(
				'auto'  => __( 'Otomatis (ikut sistem)', 'layar-katalog' ),
				'light' => __( 'Selalu terang', 'layar-katalog' ),
				'dark'  => __( 'Selalu gelap', 'layar-katalog' ),
			),
		)
	);

	/* ── Font family ── */
	$wp_customize->add_setting(
		'layarkatalog_font_family',
		array(
			'default'           => 'system',
			'sanitize_callback' => function ( $value ) {
				return in_array( $value, array( 'system', 'editorial' ), true ) ? $value : 'system';
			},
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'layarkatalog_font_family',
		array(
			'label'   => __( 'Jenis huruf utama', 'layar-katalog' ),
			'section' => 'layarkatalog_appearance',
			'type'    => 'radio',
		'choices' => array(
			'system'    => __( 'Sans-serif (Inter / system)', 'layar-katalog' ),
			'editorial' => __( 'Serif (Georgia / editorial)', 'layar-katalog' ),
		),
	)
	);

	/* ── Google Fonts (optional) ── */
	$wp_customize->add_setting(
		'layarkatalog_google_font',
		array(
			'default'           => '',
			'sanitize_callback' => function ( $value ) {
				$allowed = array( '', 'playfair', 'merriweather', 'roboto', 'lora', 'poppins' );
				return in_array( $value, $allowed, true ) ? $value : '';
			},
			'transport'         => 'refresh',
		)
	);
	$wp_customize->add_control(
		'layarkatalog_google_font',
		array(
			'label'       => __( 'Google Font (opsional)', 'layar-katalog' ),
			'description' => __( 'Muat font dari Google Fonts secara async. Kosongkan untuk memakai font sistem.', 'layar-katalog' ),
			'section'     => 'layarkatalog_appearance',
			'type'        => 'select',
			'choices'     => array(
				''             => __( 'Tidak ada (font sistem)', 'layar-katalog' ),
				'playfair'     => 'Playfair Display',
				'merriweather' => 'Merriweather',
				'roboto'       => 'Roboto',
				'lora'         => 'Lora',
				'poppins'      => 'Poppins',
			),
		)
	);
}
add_action( 'customize_register', 'layarkatalog_customize_register' );

/** Output customizer CSS inline in the page head. */
function layarkatalog_customizer_css() {
	$primary  = get_theme_mod( 'layarkatalog_primary_color', '#ccf36b' );
	$accent   = get_theme_mod( 'layarkatalog_accent_color', '#fa765d' );
	$dark     = get_theme_mod( 'layarkatalog_dark_mode', 'auto' );
	$font     = get_theme_mod( 'layarkatalog_font_family', 'system' );
	$css      = '';

	if ( '#ccf36b' !== $primary ) {
		$css .= ':root{--lk-lime:' . esc_attr( $primary ) . '}';
	}
	if ( '#fa765d' !== $accent ) {
		$css .= ':root{--lk-coral:' . esc_attr( $accent ) . '}';
	}
	if ( 'dark' === $dark ) {
		$css .= ':root{--lk-ink:#e8ebe6;--lk-ink-soft:#b0b8ae;--lk-paper:#151a17;--lk-white:#1d2320;--lk-line:rgba(232,235,230,.12);--lk-shadow:0 18px 55px rgba(0,0,0,.35)}';
		$css .= '@media(prefers-color-scheme:dark){:root{--lk-ink:#e8ebe6;--lk-ink-soft:#b0b8ae;--lk-paper:#151a17;--lk-white:#1d2320;--lk-line:rgba(232,235,230,.12);--lk-shadow:0 18px 55px rgba(0,0,0,.35)}}';
	}
	if ( 'light' === $dark ) {
		$css .= '@media(prefers-color-scheme:dark){:root{--lk-ink:#19342d;--lk-ink-soft:#4b625b;--lk-paper:#f4f5ef;--lk-white:#fffefa;--lk-line:rgba(25,52,45,.12);--lk-shadow:0 18px 55px rgba(22,43,36,.1)}}';
	}
	if ( 'editorial' === $font ) {
		$css .= 'body{font-family:Georgia,"Times New Roman",serif}';
	}
	$google = get_theme_mod( 'layarkatalog_google_font', '' );
	if ( $google ) {
		$font_map = array(
			'playfair'     => '"Playfair Display", Georgia, serif',
			'merriweather' => '"Merriweather", Georgia, serif',
			'roboto'       => '"Roboto", system-ui, sans-serif',
			'lora'         => '"Lora", Georgia, serif',
			'poppins'      => '"Poppins", system-ui, sans-serif',
		);
		if ( isset( $font_map[ $google ] ) ) {
			$css .= 'body{font-family:' . $font_map[ $google ] . '}';
			$css .= 'h1,h2,h3,h4,h5,h6{font-family:' . $font_map[ $google ] . '}';
		}
	}

	if ( $css ) {
		echo '<style id="layarkatalog-customizer-css">' . $css . '</style>' . "\n";
	}
}
add_action( 'wp_head', 'layarkatalog_customizer_css', 4 );

/** Register customizer live-preview script. */
function layarkatalog_customize_preview_js() {
	wp_enqueue_script(
		'layarkatalog-customizer-preview',
		get_template_directory_uri() . '/customizer-preview.js',
		array( 'customize-preview' ),
		wp_get_theme()->get( 'Version' ),
		true
	);
}
add_action( 'customize_preview_init', 'layarkatalog_customize_preview_js' );

/** PWA: register the web app manifest. */
function layarkatalog_pwa_manifest_link() {
	$manifest_url = get_theme_file_uri( 'manifest.json' );
	echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">' . "\n";
}
add_action( 'wp_head', 'layarkatalog_pwa_manifest_link', 5 );

/** Shortcode that renders a registered sidebar / widget area inside block templates. */
function layarkatalog_widget_area_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => '' ), $atts, 'layarkatalog_widget_area' );
	$id   = sanitize_key( $atts['id'] );
	if ( ! $id || ! is_active_sidebar( $id ) ) {
		return '';
	}
	ob_start();
	dynamic_sidebar( $id );
	return ob_get_clean();
}
add_shortcode( 'layarkatalog_widget_area', 'layarkatalog_widget_area_shortcode' );

/** Enqueue front-end enhancement scripts (lightbox, back-to-top). */
function layarkatalog_enqueue_enhancements() {
	if ( is_admin() ) {
		return;
	}
	$version = wp_get_theme()->get( 'Version' );

	wp_enqueue_script(
		'layarkatalog-lightbox',
		get_template_directory_uri() . '/assets/lightbox.js',
		array(),
		$version,
		true
	);
	wp_enqueue_script(
		'layarkatalog-back-to-top',
		get_template_directory_uri() . '/assets/back-to-top.js',
		array(),
		$version,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'layarkatalog_enqueue_enhancements', 30 );

/** Load Google Fonts asynchronously when selected in the Customizer. */
function layarkatalog_enqueue_google_fonts() {
	$font = get_theme_mod( 'layarkatalog_google_font', '' );
	if ( ! $font ) {
		return;
	}
	$urls = array();

	$presets = array(
		'playfair'  => 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700',
		'merriweather' => 'https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700',
		'roboto'    => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700',
		'lora'      => 'https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700',
		'poppins'   => 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700',
	);
	if ( isset( $presets[ $font ] ) ) {
		$urls[] = $presets[ $font ];
	}

	/**
	 * Filter the Google Font URLs loaded by the theme.
	 *
	 * @param array $urls Array of Google Fonts CSS URLs.
	 */
	$urls = apply_filters( 'layarkatalog_google_font_urls', $urls );
	$urls = array_filter( array_unique( array_map( 'esc_url_raw', $urls ) ) );

	if ( empty( $urls ) ) {
		return;
	}

	wp_enqueue_script(
		'layarkatalog-font-loader',
		get_template_directory_uri() . '/assets/font-loader.js',
		array(),
		$version,
		true
	);
	wp_localize_script( 'layarkatalog-font-loader', 'LKFonts', array( 'urls' => $urls ) );
}
add_action( 'wp_enqueue_scripts', 'layarkatalog_enqueue_google_fonts', 5 );

add_action( 'after_setup_theme', 'layarkatalog_setup' );

function layarkatalog_enqueue_styles() {
	wp_enqueue_style(
		'layar-katalog-style',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'layarkatalog_enqueue_styles' );

/** Named ad locations rendered by the block templates. */
function layarkatalog_ad_locations() {
	return array(
		'home_after_hero' => array(
			'label'       => __( 'Beranda — setelah hero', 'layar-katalog' ),
			'description' => __( 'Muncul setelah area pembuka beranda dan sebelum tautan genre.', 'layar-katalog' ),
		),
		'catalog_before_grid' => array(
			'label'       => __( 'Katalog/genre — sebelum daftar judul', 'layar-katalog' ),
			'description' => __( 'Muncul setelah filter dan sebelum kartu hasil katalog.', 'layar-katalog' ),
		),
		'episode_archive_before_list' => array(
			'label'       => __( 'Arsip episode — sebelum daftar', 'layar-katalog' ),
			'description' => __( 'Muncul sebelum daftar episode terbaru.', 'layar-katalog' ),
		),
		'title_before_episodes' => array(
			'label'       => __( 'Detail judul — sebelum daftar episode', 'layar-katalog' ),
			'description' => __( 'Muncul setelah deskripsi judul dan sebelum daftar episode.', 'layar-katalog' ),
		),
		'episode_before_player' => array(
			'label'       => __( 'Halaman episode — sebelum pemutar', 'layar-katalog' ),
			'description' => __( 'Muncul di antara informasi episode dan player.', 'layar-katalog' ),
		),
		'episode_after_player' => array(
			'label'       => __( 'Halaman episode — setelah pemutar', 'layar-katalog' ),
			'description' => __( 'Muncul setelah player dan deskripsi episode, sebelum navigasi episode.', 'layar-katalog' ),
		),
		'before_footer' => array(
			'label'       => __( 'Global — sebelum footer', 'layar-katalog' ),
			'description' => __( 'Muncul pada semua template sebelum footer situs.', 'layar-katalog' ),
		),
	);
}

function layarkatalog_get_ad_settings() {
	$saved     = get_option( 'layarkatalog_ads', array() );
	$saved     = is_array( $saved ) ? $saved : array();
	$head      = isset( $saved['global_head'] ) && is_array( $saved['global_head'] ) ? $saved['global_head'] : array();
	$saved_slots = isset( $saved['slots'] ) && is_array( $saved['slots'] ) ? $saved['slots'] : array();
	$settings  = array(
		'global_head' => array(
			'enabled' => ! empty( $head['enabled'] ),
			'code'    => isset( $head['code'] ) && is_string( $head['code'] ) ? $head['code'] : '',
		),
		'slots' => array(),
	);
	foreach ( layarkatalog_ad_locations() as $key => $location ) {
		$slot = isset( $saved_slots[ $key ] ) && is_array( $saved_slots[ $key ] ) ? $saved_slots[ $key ] : array();
		$settings['slots'][ $key ] = array(
			'enabled'     => ! empty( $slot['enabled'] ),
			'code'        => isset( $slot['code'] ) && is_string( $slot['code'] ) ? $slot['code'] : '',
			'min_height'  => isset( $slot['min_height'] ) ? min( 1200, absint( $slot['min_height'] ) ) : 0,
		);
	}
	return $settings;
}

/**
 * Keep ad code editable by trusted administrators, but strip executable markup
 * for accounts without WordPress's unfiltered_html capability.
 */
function layarkatalog_sanitize_ads_settings( $input ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return get_option( 'layarkatalog_ads', array() );
	}
	$input      = is_array( $input ) ? $input : array();
	$head_input = isset( $input['global_head'] ) && is_array( $input['global_head'] ) ? $input['global_head'] : array();
	$clean      = array(
		'global_head' => array(
			'enabled' => ! empty( $head_input['enabled'] ),
			'code'    => '',
		),
		'slots' => array(),
	);
	$may_save_scripts = current_user_can( 'unfiltered_html' );
	$sanitize_code    = function( $code ) use ( $may_save_scripts ) {
		if ( ! is_scalar( $code ) ) {
			return '';
		}
		$code = trim( (string) $code );
		if ( strlen( $code ) > 20000 ) {
			$code = substr( $code, 0, 20000 );
		}
		return $may_save_scripts ? $code : wp_kses_post( $code );
	};

	if ( isset( $head_input['code'] ) ) {
		$clean['global_head']['code'] = $sanitize_code( $head_input['code'] );
	}
	$input_slots = isset( $input['slots'] ) && is_array( $input['slots'] ) ? $input['slots'] : array();
	foreach ( layarkatalog_ad_locations() as $key => $location ) {
		$slot = isset( $input_slots[ $key ] ) && is_array( $input_slots[ $key ] ) ? $input_slots[ $key ] : array();
		$clean['slots'][ $key ] = array(
			'enabled'    => ! empty( $slot['enabled'] ),
			'code'       => isset( $slot['code'] ) ? $sanitize_code( $slot['code'] ) : '',
			'min_height' => isset( $slot['min_height'] ) ? min( 1200, absint( $slot['min_height'] ) ) : 0,
		);
	}
	return $clean;
}

function layarkatalog_register_ad_settings() {
	register_setting(
		'layarkatalog_ads_group',
		'layarkatalog_ads',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'layarkatalog_sanitize_ads_settings',
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', 'layarkatalog_register_ad_settings' );

function layarkatalog_add_ads_settings_page() {
	add_theme_page(
		__( 'Pengaturan Iklan', 'layar-katalog' ),
		__( 'Iklan', 'layar-katalog' ),
		'manage_options',
		'layarkatalog-ads',
		'layarkatalog_render_ads_settings_page'
	);
}
add_action( 'admin_menu', 'layarkatalog_add_ads_settings_page' );

function layarkatalog_render_ads_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Anda tidak memiliki izin untuk mengatur iklan.', 'layar-katalog' ) );
	}
	$settings = layarkatalog_get_ad_settings();
	?>
	<div class="wrap layarkatalog-ads-settings">
		<h1><?php esc_html_e( 'Pengaturan Iklan', 'layar-katalog' ); ?></h1>
		<p><?php esc_html_e( 'Aktifkan lokasi yang diperlukan, lalu tempel kode unit iklan. Atur tinggi minimum tiap slot sesuai ukuran unit penyedia untuk membantu mengurangi pergeseran layout. Slot yang dimatikan atau kosong tidak akan menghasilkan ruang kosong di situs.', 'layar-katalog' ); ?></p>
		<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Keamanan:', 'layar-katalog' ); ?></strong> <?php esc_html_e( 'Kode iklan dapat menjalankan JavaScript pada setiap halaman yang memuatnya. Gunakan hanya kode dari penyedia yang Anda percaya. Akun tanpa hak unfiltered_html hanya dapat menyimpan HTML yang diizinkan WordPress; tag script/iframe akan dibuang.', 'layar-katalog' ); ?></p></div>
		<?php if ( current_user_can( 'unfiltered_html' ) ) : ?>
			<p class="description"><?php esc_html_e( 'Akun Anda boleh menyimpan kode script penyedia iklan. Periksa kebijakan privasi, persetujuan cookie, dan ketentuan penyedia iklan sebelum mengaktifkannya.', 'layar-katalog' ); ?></p>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'layarkatalog_ads_group' ); ?>
			<section class="layarkatalog-ad-setting">
				<h2><?php esc_html_e( 'Kode global di area head', 'layar-katalog' ); ?></h2>
				<p><?php esc_html_e( 'Opsional. Gunakan hanya untuk loader atau verifikasi yang memang diminta dipasang pada seluruh halaman. Jangan tempel kode unit yang sama berulang kali.', 'layar-katalog' ); ?></p>
				<label><input type="checkbox" name="layarkatalog_ads[global_head][enabled]" value="1" <?php checked( $settings['global_head']['enabled'] ); ?>> <?php esc_html_e( 'Aktifkan kode global head', 'layar-katalog' ); ?></label>
				<textarea class="large-text code" rows="7" name="layarkatalog_ads[global_head][code]" aria-label="<?php esc_attr_e( 'Kode iklan global head', 'layar-katalog' ); ?>"><?php echo esc_textarea( $settings['global_head']['code'] ); ?></textarea>
			</section>
			<?php foreach ( layarkatalog_ad_locations() as $key => $location ) : ?>
				<?php $slot = $settings['slots'][ $key ]; ?>
				<section class="layarkatalog-ad-setting">
					<h2><?php echo esc_html( $location['label'] ); ?></h2>
					<p><?php echo esc_html( $location['description'] ); ?></p>
					<label><input type="checkbox" name="layarkatalog_ads[slots][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $slot['enabled'] ); ?>> <?php esc_html_e( 'Aktifkan lokasi ini', 'layar-katalog' ); ?></label>
					<p><label for="layarkatalog-ad-height-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Tinggi minimum area iklan (px; 0 = otomatis)', 'layar-katalog' ); ?></label><br>
						<input class="small-text" id="layarkatalog-ad-height-<?php echo esc_attr( $key ); ?>" type="number" min="0" max="1200" step="1" name="layarkatalog_ads[slots][<?php echo esc_attr( $key ); ?>][min_height]" value="<?php echo esc_attr( $slot['min_height'] ); ?>">
					</p>
					<p class="description"><?php esc_html_e( 'Isi tinggi yang disediakan penyedia iklan untuk unit ini. Ini membantu mengurangi pergeseran layout; gunakan 0 jika ukurannya otomatis.', 'layar-katalog' ); ?></p>
					<textarea class="large-text code" rows="7" name="layarkatalog_ads[slots][<?php echo esc_attr( $key ); ?>][code]" aria-label="<?php echo esc_attr( sprintf( __( 'Kode iklan: %s', 'layar-katalog' ), $location['label'] ) ); ?>"><?php echo esc_textarea( $slot['code'] ); ?></textarea>
				</section>
			<?php endforeach; ?>
			<?php submit_button( __( 'Simpan pengaturan iklan', 'layar-katalog' ) ); ?>
		</form>
	</div>
	<style>
		.layarkatalog-ads-settings .layarkatalog-ad-setting{max-width:980px;margin:22px 0;padding:18px 20px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
		.layarkatalog-ads-settings .layarkatalog-ad-setting h2{margin-top:0}
		.layarkatalog-ads-settings .layarkatalog-ad-setting textarea{display:block;margin-top:12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
	</style>
	<?php
}

/** Print an optional ad network loader in wp_head. */
function layarkatalog_output_global_ad_code() {
	if ( is_admin() ) {
		return;
	}
	$settings = layarkatalog_get_ad_settings();
	$global   = $settings['global_head'];
	if ( $global['enabled'] && trim( $global['code'] ) ) {
		echo "\n" . $global['code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- saved by an administrator with settings capability.
	}
}
add_action( 'wp_head', 'layarkatalog_output_global_ad_code', 1 );

/** Theme shortcode used by templates to render a selected ad location. */
function layarkatalog_ad_slot_shortcode( $atts = array() ) {
	if ( is_admin() ) {
		return '';
	}
	$atts = shortcode_atts( array( 'slot' => '' ), $atts, 'layarkatalog_ad_slot' );
	$slot = sanitize_key( $atts['slot'] );
	if ( ! isset( layarkatalog_ad_locations()[ $slot ] ) ) {
		return '';
	}
	$settings = layarkatalog_get_ad_settings();
	$ad       = isset( $settings['slots'][ $slot ] ) ? $settings['slots'][ $slot ] : array();
	if ( empty( $ad['enabled'] ) || empty( $ad['code'] ) ) {
		return '';
	}
	$location   = layarkatalog_ad_locations()[ $slot ];
	$min_height = isset( $ad['min_height'] ) ? min( 1200, absint( $ad['min_height'] ) ) : 0;
	$height_css = $min_height ? ' style="min-height:' . $min_height . 'px"' : '';
	return '<aside class="lk-ad-slot lk-ad-slot--' . esc_attr( $slot ) . '" aria-label="' . esc_attr( sprintf( __( 'Iklan — %s', 'layar-katalog' ), $location['label'] ) ) . '"><span class="lk-ad-slot__label">' . esc_html__( 'Iklan', 'layar-katalog' ) . '</span><div class="lk-ad-slot__content"' . $height_css . '>' . $ad['code'] . '</div></aside>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted ad code is capability-gated and sanitized on save.
}
add_shortcode( 'layarkatalog_ad_slot', 'layarkatalog_ad_slot_shortcode' );
