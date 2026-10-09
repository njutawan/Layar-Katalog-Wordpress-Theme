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
	add_editor_style( 'style.css' );
}
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
