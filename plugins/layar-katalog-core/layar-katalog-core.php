<?php
/**
 * Plugin Name: Layar Katalog Core
 * Description: Katalog/Episode, pencarian/filter, SEO, watchlist/riwayat lokal, rating akun, dan notifikasi episode Web Push VAPID.
 * Version: 1.4.0
 * Requires at least: 6.3
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: Layar Katalog
 * Text Domain: layar-katalog-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LKC_PLUGIN_FILE' ) ) {
	define( 'LKC_PLUGIN_FILE', __FILE__ );
	define( 'LKC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
	define( 'LKC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
	define( 'LKC_PLUGIN_VERSION', '1.4.0' );
}

$lkc_autoload = LKC_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $lkc_autoload ) ) {
	require_once $lkc_autoload;
}
require_once LKC_PLUGIN_DIR . 'includes/engagement.php';
require_once LKC_PLUGIN_DIR . 'includes/web-push.php';

/** Register content types used by the catalog theme. */
function lkc_register_post_types() {
	$catalog_labels = array(
		'name'                  => __( 'Katalog', 'layar-katalog-core' ),
		'singular_name'         => __( 'Item Katalog', 'layar-katalog-core' ),
		'add_new'               => __( 'Tambah Item', 'layar-katalog-core' ),
		'add_new_item'          => __( 'Tambah Item Katalog', 'layar-katalog-core' ),
		'edit_item'             => __( 'Edit Item Katalog', 'layar-katalog-core' ),
		'new_item'              => __( 'Item Katalog Baru', 'layar-katalog-core' ),
		'view_item'             => __( 'Lihat Item', 'layar-katalog-core' ),
		'view_items'            => __( 'Lihat Katalog', 'layar-katalog-core' ),
		'search_items'          => __( 'Cari Katalog', 'layar-katalog-core' ),
		'not_found'             => __( 'Item katalog belum ditemukan.', 'layar-katalog-core' ),
		'not_found_in_trash'    => __( 'Tidak ada item katalog di tong sampah.', 'layar-katalog-core' ),
		'all_items'             => __( 'Semua Item', 'layar-katalog-core' ),
		'menu_name'             => __( 'Katalog', 'layar-katalog-core' ),
		'name_admin_bar'        => __( 'Item Katalog', 'layar-katalog-core' ),
	);

	register_post_type(
		'lk_title',
		array(
			'labels'             => $catalog_labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_in_rest'       => true,
			'has_archive'        => true,
			'rewrite'            => array( 'slug' => 'katalog', 'with_front' => false ),
			'menu_icon'          => 'dashicons-screenoptions',
			'menu_position'      => 20,
			'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'comments' ),
			'taxonomies'         => array( 'category', 'post_tag' ),
			'show_in_nav_menus'  => true,
			'show_in_admin_bar'  => true,
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
		)
	);

	$episode_labels = array(
		'name'                  => __( 'Episode', 'layar-katalog-core' ),
		'singular_name'         => __( 'Episode', 'layar-katalog-core' ),
		'add_new'               => __( 'Tambah Episode', 'layar-katalog-core' ),
		'add_new_item'          => __( 'Tambah Episode', 'layar-katalog-core' ),
		'edit_item'             => __( 'Edit Episode', 'layar-katalog-core' ),
		'new_item'              => __( 'Episode Baru', 'layar-katalog-core' ),
		'view_item'             => __( 'Lihat Episode', 'layar-katalog-core' ),
		'search_items'          => __( 'Cari Episode', 'layar-katalog-core' ),
		'not_found'             => __( 'Episode belum ditemukan.', 'layar-katalog-core' ),
		'not_found_in_trash'    => __( 'Tidak ada episode di tong sampah.', 'layar-katalog-core' ),
		'all_items'             => __( 'Semua Episode', 'layar-katalog-core' ),
		'menu_name'             => __( 'Episode', 'layar-katalog-core' ),
		'name_admin_bar'        => __( 'Episode', 'layar-katalog-core' ),
	);

	register_post_type(
		'lk_episode',
		array(
			'labels'              => $episode_labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_in_rest'        => true,
			'has_archive'         => 'episode',
			'rewrite'             => array( 'slug' => 'episode', 'with_front' => false ),
			'menu_icon'           => 'dashicons-controls-play',
			'menu_position'       => 21,
			'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ),
			'show_in_nav_menus'   => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		)
	);
}
add_action( 'init', 'lkc_register_post_types' );

/** Add an editorial genre taxonomy for browse, filters, and genre archives. */
function lkc_register_taxonomies() {
	$labels = array(
		'name'                       => __( 'Genre', 'layar-katalog-core' ),
		'singular_name'              => __( 'Genre', 'layar-katalog-core' ),
		'search_items'               => __( 'Cari Genre', 'layar-katalog-core' ),
		'all_items'                  => __( 'Semua Genre', 'layar-katalog-core' ),
		'edit_item'                  => __( 'Edit Genre', 'layar-katalog-core' ),
		'update_item'                => __( 'Perbarui Genre', 'layar-katalog-core' ),
		'add_new_item'               => __( 'Tambah Genre', 'layar-katalog-core' ),
		'new_item_name'              => __( 'Nama Genre Baru', 'layar-katalog-core' ),
		'menu_name'                  => __( 'Genre', 'layar-katalog-core' ),
		'not_found'                  => __( 'Genre belum tersedia.', 'layar-katalog-core' ),
	);

	register_taxonomy(
		'lk_genre',
		array( 'lk_title' ),
		array(
			'labels'            => $labels,
			'public'            => true,
			'hierarchical'      => false,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => true,
			'rewrite'           => array( 'slug' => 'genre', 'with_front' => false ),
		)
	);
}
add_action( 'init', 'lkc_register_taxonomies', 11 );

/** Register metadata so it is permission-checked and available to the REST API. */
function lkc_register_meta() {
	$can_edit_meta = function( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	};

	$title_meta = array(
		'_lk_format' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_format',
			'default'           => '',
		),
		'_lk_year' => array(
			'type'              => 'integer',
			'sanitize_callback' => 'lkc_sanitize_year',
			'default'           => 0,
		),
		'_lk_status' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_status',
			'default'           => '',
		),
		'_lk_rating' => array(
			'type'              => 'number',
			'sanitize_callback' => 'lkc_sanitize_rating',
			'default'           => 0,
		),
		'_lk_featured' => array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		),
		'_lk_original_title' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_original_title',
			'default'           => '',
		),
		'_lk_producers' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_producers',
			'default'           => '',
		),
		'_lk_duration' => array(
			'type'              => 'integer',
			'sanitize_callback' => 'lkc_sanitize_duration',
			'default'           => 0,
		),
		'_lk_seo_title' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_seo_title',
			'default'           => '',
		),
		'_lk_seo_description' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_seo_description',
			'default'           => '',
		),
	);

	$episode_meta = array(
		'_lk_parent_title' => array(
			'type'              => 'integer',
			'sanitize_callback' => 'lkc_sanitize_parent_title',
			'default'           => 0,
		),
		'_lk_season' => array(
			'type'              => 'integer',
			'sanitize_callback' => 'lkc_sanitize_season',
			'default'           => 1,
		),
		'_lk_episode_number' => array(
			'type'              => 'number',
			'sanitize_callback' => 'lkc_sanitize_episode_number',
			'default'           => 0,
		),
		'_lk_runtime' => array(
			'type'              => 'integer',
			'sanitize_callback' => 'lkc_sanitize_runtime',
			'default'           => 0,
		),
		'_lk_video_url' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_video_url',
			'default'           => '',
		),
		'_lk_video_url_2' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_video_url',
			'default'           => '',
		),
		'_lk_video_url_3' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_video_url',
			'default'           => '',
		),
		'_lk_seo_title' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_seo_title',
			'default'           => '',
		),
		'_lk_seo_description' => array(
			'type'              => 'string',
			'sanitize_callback' => 'lkc_sanitize_seo_description',
			'default'           => '',
		),
	);

	foreach ( $title_meta as $meta_key => $args ) {
		$args['single']        = true;
		$args['show_in_rest']  = true;
		$args['auth_callback'] = $can_edit_meta;
		register_post_meta( 'lk_title', $meta_key, $args );
	}

	foreach ( $episode_meta as $meta_key => $args ) {
		$args['single']        = true;
		$args['show_in_rest']  = true;
		$args['auth_callback'] = $can_edit_meta;
		register_post_meta( 'lk_episode', $meta_key, $args );
	}
}
add_action( 'init', 'lkc_register_meta' );

function lkc_sanitize_format( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$value   = sanitize_key( (string) $value );
	$formats = array( 'animation', 'film', 'series', 'other' );
	return in_array( $value, $formats, true ) ? $value : '';
}

function lkc_sanitize_status( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$value   = sanitize_key( (string) $value );
	$statuses = array( 'upcoming', 'ongoing', 'completed' );
	return in_array( $value, $statuses, true ) ? $value : '';
}

function lkc_sanitize_year( $value ) {
	if ( ! is_scalar( $value ) || '' === $value || null === $value ) {
		return 0;
	}
	$year = filter_var( $value, FILTER_VALIDATE_INT );
	return ( false !== $year && $year >= 1888 && $year <= 2100 ) ? (int) $year : 0;
}

function lkc_sanitize_rating( $value ) {
	if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
		return 0;
	}
	return round( max( 0, min( 10, (float) $value ) ), 1 );
}

function lkc_sanitize_original_title( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	return wp_html_excerpt( sanitize_text_field( (string) $value ), 160, '' );
}

function lkc_sanitize_producers( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	return wp_html_excerpt( sanitize_text_field( (string) $value ), 200, '' );
}

function lkc_sanitize_duration( $value ) {
	if ( ! is_numeric( $value ) || (float) $value < 0 ) {
		return 0;
	}
	return min( 999, (int) floor( (float) $value ) );
}

function lkc_sanitize_parent_title( $value ) {
	if ( ! is_scalar( $value ) ) {
		return 0;
	}
	$parent_id = filter_var( $value, FILTER_VALIDATE_INT );
	if ( false === $parent_id || $parent_id < 1 ) {
		return 0;
	}
	return ( 'lk_title' === get_post_type( $parent_id ) ) ? (int) $parent_id : 0;
}

function lkc_sanitize_season( $value ) {
	if ( ! is_numeric( $value ) || (float) $value < 1 ) {
		return 1;
	}
	return min( 999, (int) floor( (float) $value ) );
}

function lkc_sanitize_episode_number( $value ) {
	if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
		return 0;
	}
	return round( max( 0, min( 9999, (float) $value ) ), 1 );
}

function lkc_sanitize_runtime( $value ) {
	if ( '' === $value || null === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
		return 0;
	}
	return min( 999, (int) floor( (float) $value ) );
}

function lkc_sanitize_seo_title( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	return wp_html_excerpt( sanitize_text_field( (string) $value ), 70, '' );
}

function lkc_sanitize_seo_description( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$value = sanitize_textarea_field( (string) $value );
	$value = preg_replace( '/\s+/u', ' ', $value );
	return wp_html_excerpt( trim( $value ), 180, '' );
}

function lkc_format_episode_number( $value ) {
	if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
		return '—';
	}
	$number = (float) $value;
	return ( floor( $number ) === $number ) ? sprintf( '%02d', (int) $number ) : rtrim( rtrim( number_format( $number, 1, '.', '' ), '0' ), '.' );
}

/** Only allow direct HTTP(S) MP4/WebM files; never accept raw iframe/embed markup. */
function lkc_sanitize_video_url( $value ) {
	if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
		return '';
	}
	$url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
	if ( ! $url || ! lkc_is_allowed_video_url( $url ) ) {
		return '';
	}
	return $url;
}

function lkc_is_allowed_video_url( $url ) {
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
	$ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	return in_array( $scheme, array( 'http', 'https' ), true ) && in_array( $ext, array( 'mp4', 'webm' ), true );
}

/** Add editorial and episode fields in the WordPress editor. */
function lkc_add_meta_boxes() {
	add_meta_box( 'lkc-title-data', __( 'Data Katalog', 'layar-katalog-core' ), 'lkc_render_title_meta_box', 'lk_title', 'normal', 'high' );
	add_meta_box( 'lkc-episode-data', __( 'Data Episode & Video', 'layar-katalog-core' ), 'lkc_render_episode_meta_box', 'lk_episode', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'lkc_add_meta_boxes' );

function lkc_render_title_meta_box( $post ) {
	wp_nonce_field( 'lkc_save_meta', 'lkc_meta_nonce' );
	$format = get_post_meta( $post->ID, '_lk_format', true );
	$year   = get_post_meta( $post->ID, '_lk_year', true );
	$status = get_post_meta( $post->ID, '_lk_status', true );
	$rating          = get_post_meta( $post->ID, '_lk_rating', true );
	$featured        = get_post_meta( $post->ID, '_lk_featured', true );
	$original_title  = get_post_meta( $post->ID, '_lk_original_title', true );
	$producers       = get_post_meta( $post->ID, '_lk_producers', true );
	$duration        = get_post_meta( $post->ID, '_lk_duration', true );
	$seo_title       = get_post_meta( $post->ID, '_lk_seo_title', true );
	$seo_description = get_post_meta( $post->ID, '_lk_seo_description', true );
	$formats = array(
		''          => __( 'Pilih format', 'layar-katalog-core' ),
		'animation' => __( 'Animasi', 'layar-katalog-core' ),
		'film'      => __( 'Film', 'layar-katalog-core' ),
		'series'    => __( 'Serial', 'layar-katalog-core' ),
		'other'     => __( 'Lainnya', 'layar-katalog-core' ),
	);
	$statuses = array(
		''          => __( 'Pilih status', 'layar-katalog-core' ),
		'upcoming'  => __( 'Akan datang', 'layar-katalog-core' ),
		'ongoing'   => __( 'Berjalan', 'layar-katalog-core' ),
		'completed' => __( 'Selesai', 'layar-katalog-core' ),
	);
	?>
	<div class="lkc-admin-grid">
		<p>
			<label for="lkc-format"><strong><?php esc_html_e( 'Format', 'layar-katalog-core' ); ?></strong></label><br>
			<select id="lkc-format" name="lkc_format" class="widefat">
				<?php foreach ( $formats as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $format, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="lkc-year"><strong><?php esc_html_e( 'Tahun rilis', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-year" name="lkc_year" type="number" min="1888" max="2100" step="1" value="<?php echo esc_attr( $year ); ?>" class="small-text">
		</p>
		<p>
			<label for="lkc-status"><strong><?php esc_html_e( 'Status', 'layar-katalog-core' ); ?></strong></label><br>
			<select id="lkc-status" name="lkc_status" class="widefat">
				<?php foreach ( $statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="lkc-rating"><strong><?php esc_html_e( 'Rating editorial (0–10)', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-rating" name="lkc_rating" type="number" min="0" max="10" step="0.1" value="<?php echo esc_attr( $rating ); ?>" class="small-text">
		</p>
		<p class="lkc-admin-wide">
			<label><input type="checkbox" name="lkc_featured" value="1" <?php checked( $featured, '1' ); ?>> <strong><?php esc_html_e( 'Tampilkan sebagai pilihan editor di beranda', 'layar-katalog-core' ); ?></strong></label>
		</p>
		<p class="lkc-admin-wide">
			<label for="lkc-original-title"><strong><?php esc_html_e( 'Judul asli', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-original-title" name="lkc_original_title" type="text" maxlength="160" value="<?php echo esc_attr( $original_title ); ?>" class="widefat">
		</p>
		<p>
			<label for="lkc-producers"><strong><?php esc_html_e( 'Studio / produser', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-producers" name="lkc_producers" type="text" maxlength="200" value="<?php echo esc_attr( $producers ); ?>" class="widefat">
		</p>
		<p>
			<label for="lkc-duration"><strong><?php esc_html_e( 'Durasi (menit)', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-duration" name="lkc_duration" type="number" min="0" max="999" step="1" value="<?php echo esc_attr( $duration ); ?>" class="small-text">
		</p>
		<?php if ( lkc_is_seo_plugin_active() ) : ?>
			<p class="lkc-admin-wide description"><?php esc_html_e( 'Plugin SEO terdeteksi. Gunakan kolom judul/deskripsi dari plugin tersebut; keluaran SEO bawaan Layar Katalog dimatikan agar tidak duplikat.', 'layar-katalog-core' ); ?></p>
		<?php else : ?>
			<p class="lkc-admin-wide">
				<label for="lkc-seo-title"><strong><?php esc_html_e( 'Judul SEO (opsional)', 'layar-katalog-core' ); ?></strong></label><br>
				<input id="lkc-seo-title" name="lkc_seo_title" type="text" maxlength="70" value="<?php echo esc_attr( $seo_title ); ?>" class="widefat" placeholder="<?php echo esc_attr( get_the_title( $post ) ); ?>">
			</p>
			<p class="lkc-admin-wide">
				<label for="lkc-seo-description"><strong><?php esc_html_e( 'Deskripsi SEO (opsional)', 'layar-katalog-core' ); ?></strong></label><br>
				<textarea id="lkc-seo-description" name="lkc_seo_description" maxlength="180" rows="3" class="widefat"><?php echo esc_textarea( $seo_description ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Kosongkan untuk memakai ringkasan/awal konten secara otomatis. Usahakan deskripsi unik, jelas, dan ringkas.', 'layar-katalog-core' ); ?></span>
			</p>
		<?php endif; ?>
	</div>
	<p class="description"><?php esc_html_e( 'Rating ini adalah nilai editorial yang diisi pengelola, bukan sistem voting pengunjung.', 'layar-katalog-core' ); ?></p>
	<?php
}

function lkc_render_episode_meta_box( $post ) {
	wp_nonce_field( 'lkc_save_meta', 'lkc_meta_nonce' );
	$parent_id = absint( get_post_meta( $post->ID, '_lk_parent_title', true ) );
	$season    = get_post_meta( $post->ID, '_lk_season', true );
	$number    = get_post_meta( $post->ID, '_lk_episode_number', true );
	$runtime         = get_post_meta( $post->ID, '_lk_runtime', true );
	$video_url       = get_post_meta( $post->ID, '_lk_video_url', true );
	$video_url_2     = get_post_meta( $post->ID, '_lk_video_url_2', true );
	$video_url_3     = get_post_meta( $post->ID, '_lk_video_url_3', true );
	$seo_title       = get_post_meta( $post->ID, '_lk_seo_title', true );
	$seo_description = get_post_meta( $post->ID, '_lk_seo_description', true );
	$titles          = get_posts(
		array(
			'post_type'      => 'lk_title',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 500,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	?>
	<div class="lkc-admin-grid">
		<p class="lkc-admin-wide">
			<label for="lkc-parent"><strong><?php esc_html_e( 'Judul katalog induk', 'layar-katalog-core' ); ?></strong></label><br>
			<select id="lkc-parent" name="lkc_parent_title" class="widefat">
				<option value="0"><?php esc_html_e( 'Pilih item katalog', 'layar-katalog-core' ); ?></option>
				<?php foreach ( $titles as $title ) : ?>
					<option value="<?php echo esc_attr( $title->ID ); ?>" <?php selected( $parent_id, $title->ID ); ?>><?php echo esc_html( get_the_title( $title ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="lkc-season"><strong><?php esc_html_e( 'Musim', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-season" name="lkc_season" type="number" min="1" max="999" step="1" value="<?php echo esc_attr( '' !== $season ? $season : 1 ); ?>" class="small-text">
		</p>
		<p>
			<label for="lkc-episode-number"><strong><?php esc_html_e( 'Nomor episode', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-episode-number" name="lkc_episode_number" type="number" min="0" max="9999" step="0.1" value="<?php echo esc_attr( $number ); ?>" class="small-text">
			<span class="description"><?php esc_html_e( 'Contoh: 1, 2, atau 2.5', 'layar-katalog-core' ); ?></span>
		</p>
		<p>
			<label for="lkc-runtime"><strong><?php esc_html_e( 'Durasi (menit)', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-runtime" name="lkc_runtime" type="number" min="0" max="999" step="1" value="<?php echo esc_attr( $runtime ); ?>" class="small-text">
		</p>
		<p class="lkc-admin-wide">
			<label for="lkc-video-url"><strong><?php esc_html_e( 'Video utama (MP4/WebM)', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-video-url" name="lkc_video_url" type="url" value="<?php echo esc_attr( $video_url ); ?>" class="widefat" placeholder="https://…/video.mp4">
			<span class="description"><?php esc_html_e( 'Gunakan file yang Anda miliki atau berhak tayangkan. Hanya URL langsung HTTP/HTTPS berekstensi MP4/WebM; embed/iframe tidak diterima.', 'layar-katalog-core' ); ?></span>
		</p>
		<p class="lkc-admin-wide">
			<label for="lkc-video-url-2"><strong><?php esc_html_e( 'Sumber cadangan 2 (opsional)', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-video-url-2" name="lkc_video_url_2" type="url" value="<?php echo esc_attr( $video_url_2 ); ?>" class="widefat" placeholder="https://…/mirror.mp4">
		</p>
		<p class="lkc-admin-wide">
			<label for="lkc-video-url-3"><strong><?php esc_html_e( 'Sumber cadangan 3 (opsional)', 'layar-katalog-core' ); ?></strong></label><br>
			<input id="lkc-video-url-3" name="lkc_video_url_3" type="url" value="<?php echo esc_attr( $video_url_3 ); ?>" class="widefat" placeholder="https://…/backup.webm">
		</p>
		<?php if ( lkc_is_seo_plugin_active() ) : ?>
			<p class="lkc-admin-wide description"><?php esc_html_e( 'Plugin SEO terdeteksi. Gunakan kolom judul/deskripsi dari plugin tersebut; keluaran SEO bawaan Layar Katalog dimatikan agar tidak duplikat.', 'layar-katalog-core' ); ?></p>
		<?php else : ?>
			<p class="lkc-admin-wide">
				<label for="lkc-seo-title"><strong><?php esc_html_e( 'Judul SEO (opsional)', 'layar-katalog-core' ); ?></strong></label><br>
				<input id="lkc-seo-title" name="lkc_seo_title" type="text" maxlength="70" value="<?php echo esc_attr( $seo_title ); ?>" class="widefat" placeholder="<?php echo esc_attr( get_the_title( $post ) ); ?>">
			</p>
			<p class="lkc-admin-wide">
				<label for="lkc-seo-description"><strong><?php esc_html_e( 'Deskripsi SEO (opsional)', 'layar-katalog-core' ); ?></strong></label><br>
				<textarea id="lkc-seo-description" name="lkc_seo_description" maxlength="180" rows="3" class="widefat"><?php echo esc_textarea( $seo_description ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Kosongkan untuk memakai ringkasan/awal konten secara otomatis. Usahakan deskripsi unik, jelas, dan ringkas.', 'layar-katalog-core' ); ?></span>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/** Save the metabox fields after WordPress has verified the editor request. */
function lkc_save_meta( $post_id ) {
	if ( ! isset( $_POST['lkc_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lkc_meta_nonce'] ) ), 'lkc_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$post_type = get_post_type( $post_id );
	if ( 'lk_title' === $post_type ) {
		$format = isset( $_POST['lkc_format'] ) ? lkc_sanitize_format( wp_unslash( $_POST['lkc_format'] ) ) : '';
		$year   = isset( $_POST['lkc_year'] ) ? lkc_sanitize_year( wp_unslash( $_POST['lkc_year'] ) ) : 0;
		$status = isset( $_POST['lkc_status'] ) ? lkc_sanitize_status( wp_unslash( $_POST['lkc_status'] ) ) : '';
		$rating         = isset( $_POST['lkc_rating'] ) ? lkc_sanitize_rating( wp_unslash( $_POST['lkc_rating'] ) ) : 0;
		$featured       = isset( $_POST['lkc_featured'] ) && '1' === wp_unslash( $_POST['lkc_featured'] );
		$original_title = isset( $_POST['lkc_original_title'] ) ? lkc_sanitize_original_title( wp_unslash( $_POST['lkc_original_title'] ) ) : '';
		$producers      = isset( $_POST['lkc_producers'] ) ? lkc_sanitize_producers( wp_unslash( $_POST['lkc_producers'] ) ) : '';
		$duration       = isset( $_POST['lkc_duration'] ) ? lkc_sanitize_duration( wp_unslash( $_POST['lkc_duration'] ) ) : 0;

		lkc_store_meta( $post_id, '_lk_format', $format );
		lkc_store_meta( $post_id, '_lk_year', $year ? $year : '' );
		lkc_store_meta( $post_id, '_lk_status', $status );
		lkc_store_meta( $post_id, '_lk_rating', ( $rating > 0 ) ? $rating : '' );
		lkc_store_meta( $post_id, '_lk_featured', $featured ? 1 : '' );
		lkc_store_meta( $post_id, '_lk_original_title', $original_title );
		lkc_store_meta( $post_id, '_lk_producers', $producers );
		lkc_store_meta( $post_id, '_lk_duration', $duration ? $duration : '' );
		if ( isset( $_POST['lkc_seo_title'] ) ) {
			lkc_store_meta( $post_id, '_lk_seo_title', lkc_sanitize_seo_title( wp_unslash( $_POST['lkc_seo_title'] ) ) );
		}
		if ( isset( $_POST['lkc_seo_description'] ) ) {
			lkc_store_meta( $post_id, '_lk_seo_description', lkc_sanitize_seo_description( wp_unslash( $_POST['lkc_seo_description'] ) ) );
		}
	} elseif ( 'lk_episode' === $post_type ) {
		$parent_id = isset( $_POST['lkc_parent_title'] ) ? lkc_sanitize_parent_title( wp_unslash( $_POST['lkc_parent_title'] ) ) : 0;
		$season    = isset( $_POST['lkc_season'] ) ? lkc_sanitize_season( wp_unslash( $_POST['lkc_season'] ) ) : 1;
		$raw_number = isset( $_POST['lkc_episode_number'] ) ? trim( (string) wp_unslash( $_POST['lkc_episode_number'] ) ) : '';
		$number     = lkc_sanitize_episode_number( $raw_number );
		$runtime    = isset( $_POST['lkc_runtime'] ) ? lkc_sanitize_runtime( wp_unslash( $_POST['lkc_runtime'] ) ) : 0;
		$video_url   = isset( $_POST['lkc_video_url'] ) ? lkc_sanitize_video_url( wp_unslash( $_POST['lkc_video_url'] ) ) : '';
		$video_url_2 = isset( $_POST['lkc_video_url_2'] ) ? lkc_sanitize_video_url( wp_unslash( $_POST['lkc_video_url_2'] ) ) : '';
		$video_url_3 = isset( $_POST['lkc_video_url_3'] ) ? lkc_sanitize_video_url( wp_unslash( $_POST['lkc_video_url_3'] ) ) : '';

		lkc_store_meta( $post_id, '_lk_parent_title', $parent_id );
		lkc_store_meta( $post_id, '_lk_season', $season );
		lkc_store_meta( $post_id, '_lk_episode_number', ( '' !== $raw_number ) ? $number : '' );
		lkc_store_meta( $post_id, '_lk_runtime', $runtime );
		lkc_store_meta( $post_id, '_lk_video_url', $video_url );
		lkc_store_meta( $post_id, '_lk_video_url_2', $video_url_2 );
		lkc_store_meta( $post_id, '_lk_video_url_3', $video_url_3 );
		if ( isset( $_POST['lkc_seo_title'] ) ) {
			lkc_store_meta( $post_id, '_lk_seo_title', lkc_sanitize_seo_title( wp_unslash( $_POST['lkc_seo_title'] ) ) );
		}
		if ( isset( $_POST['lkc_seo_description'] ) ) {
			lkc_store_meta( $post_id, '_lk_seo_description', lkc_sanitize_seo_description( wp_unslash( $_POST['lkc_seo_description'] ) ) );
		}
	}
}
add_action( 'save_post', 'lkc_save_meta' );

function lkc_store_meta( $post_id, $key, $value ) {
	if ( '' === $value || null === $value ) {
		delete_post_meta( $post_id, $key );
		return;
	}
	update_post_meta( $post_id, $key, $value );
}

/** Display a compact editorial data panel in a catalog entry. */
function lkc_catalog_details_shortcode( $atts = array() ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_catalog_details' );
	$post_id = absint( $atts['id'] );
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	if ( ! $post_id || 'lk_title' !== get_post_type( $post_id ) ) {
		return '';
	}

	$format_labels = array(
		'animation' => __( 'Animasi', 'layar-katalog-core' ),
		'film'      => __( 'Film', 'layar-katalog-core' ),
		'series'    => __( 'Serial', 'layar-katalog-core' ),
		'other'     => __( 'Lainnya', 'layar-katalog-core' ),
	);
	$status_labels = array(
		'upcoming'  => __( 'Akan datang', 'layar-katalog-core' ),
		'ongoing'   => __( 'Berjalan', 'layar-katalog-core' ),
		'completed' => __( 'Selesai', 'layar-katalog-core' ),
	);
	$format         = get_post_meta( $post_id, '_lk_format', true );
	$year           = absint( get_post_meta( $post_id, '_lk_year', true ) );
	$status         = get_post_meta( $post_id, '_lk_status', true );
	$rating         = (float) get_post_meta( $post_id, '_lk_rating', true );
	$original_title = get_post_meta( $post_id, '_lk_original_title', true );
	$producers      = get_post_meta( $post_id, '_lk_producers', true );
	$duration       = absint( get_post_meta( $post_id, '_lk_duration', true ) );
	$items          = array();

	if ( $original_title ) {
		$items[] = '<div class="lk-detail"><dt>' . esc_html__( 'Judul asli', 'layar-katalog-core' ) . '</dt><dd>' . esc_html( $original_title ) . '</dd></div>';
	}
	if ( isset( $format_labels[ $format ] ) ) {
		$items[] = '<div class="lk-detail"><dt>' . esc_html__( 'Format', 'layar-katalog-core' ) . '</dt><dd>' . esc_html( $format_labels[ $format ] ) . '</dd></div>';
	}
	if ( $year ) {
		$items[] = '<div class="lk-detail"><dt>' . esc_html__( 'Tahun', 'layar-katalog-core' ) . '</dt><dd>' . esc_html( (string) $year ) . '</dd></div>';
	}
	if ( $producers ) {
		$items[] = '<div class="lk-detail"><dt>' . esc_html__( 'Studio / produser', 'layar-katalog-core' ) . '</dt><dd>' . esc_html( $producers ) . '</dd></div>';
	}
	if ( $duration ) {
		$items[] = '<div class="lk-detail"><dt>' . esc_html__( 'Durasi', 'layar-katalog-core' ) . '</dt><dd>' . esc_html( sprintf( __( '%d menit', 'layar-katalog-core' ), $duration ) ) . '</dd></div>';
	}
	$genres = get_the_term_list( $post_id, 'lk_genre', '', ', ' );
	if ( $genres && ! is_wp_error( $genres ) ) {
		$items[] = '<div class="lk-detail"><dt>' . esc_html__( 'Genre', 'layar-katalog-core' ) . '</dt><dd>' . wp_kses_post( $genres ) . '</dd></div>';
	}
	if ( is_singular( 'lk_title' ) && get_queried_object_id() === $post_id ) {
		$episodes_query = new WP_Query(
			array(
				'post_type'              => 'lk_episode',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_lk_parent_title',
					'value'   => $post_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
				),
				),
			)
		);
		if ( $episodes_query->found_posts ) {
			$items[] = '<div class="lk-detail"><dt>' . esc_html__( 'Jumlah episode', 'layar-katalog-core' ) . '</dt><dd>' . esc_html( number_format_i18n( $episodes_query->found_posts ) ) . '</dd></div>';
		}
	}
	if ( isset( $status_labels[ $status ] ) ) {
		$items[] = '<div class="lk-detail"><dt>' . esc_html__( 'Status', 'layar-katalog-core' ) . '</dt><dd>' . esc_html( $status_labels[ $status ] ) . '</dd></div>';
	}
	if ( $rating > 0 ) {
		$rating_text = number_format_i18n( $rating, 1 );
		$items[] = '<div class="lk-detail lk-detail-rating"><dt>' . esc_html__( 'Rating editorial', 'layar-katalog-core' ) . '</dt><dd><span aria-hidden="true">★</span> ' . esc_html( $rating_text ) . ' <small>/ 10</small></dd></div>';
	}
	if ( empty( $items ) ) {
		return '';
	}

	return '<dl class="lk-catalog-details">' . implode( '', $items ) . '</dl>';
}
add_shortcode( 'lk_catalog_details', 'lkc_catalog_details_shortcode' );

/** Compare episode ordering consistently for lists and previous/next navigation. */
function lkc_compare_episode_order( $a, $b ) {
	$season_a = (int) get_post_meta( $a->ID, '_lk_season', true );
	$season_b = (int) get_post_meta( $b->ID, '_lk_season', true );
	if ( $season_a !== $season_b ) {
		return $season_a <=> $season_b;
	}

	$raw_number_a = get_post_meta( $a->ID, '_lk_episode_number', true );
	$raw_number_b = get_post_meta( $b->ID, '_lk_episode_number', true );
	$number_a     = is_numeric( $raw_number_a ) ? (float) $raw_number_a : PHP_FLOAT_MAX;
	$number_b     = is_numeric( $raw_number_b ) ? (float) $raw_number_b : PHP_FLOAT_MAX;
	if ( $number_a !== $number_b ) {
		return $number_a <=> $number_b;
	}

	$title_order = strcasecmp( $a->post_title, $b->post_title );
	return $title_order ? $title_order : ( (int) $a->ID <=> (int) $b->ID );
}

/**
 * Load a title's ordered episode IDs once, then reuse them across list and
 * navigation shortcodes. A versioned transient is invalidated on episode edits.
 */
function lkc_get_ordered_episodes_for_title( $title_id ) {
	$title_id = absint( $title_id );
	if ( ! $title_id ) {
		return array();
	}

	$version   = max( 1, absint( get_option( 'lkc_episode_order_cache_version', 1 ) ) );
	$cache_key = 'lkc_episode_order_' . $title_id . '_' . $version;
	$cached_ids = get_transient( $cache_key );
	if ( false !== $cached_ids && is_array( $cached_ids ) ) {
		$episode_ids = array_values( array_unique( array_filter( array_map( 'absint', $cached_ids ) ) ) );
		if ( empty( $episode_ids ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'              => 'lk_episode',
				'post_status'            => 'publish',
				'posts_per_page'         => count( $episode_ids ),
				'post__in'               => $episode_ids,
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
	}

	$episodes = get_posts(
		array(
			'post_type'              => 'lk_episode',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'     => '_lk_parent_title',
				'value'   => $title_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
				),
			),
		)
	);
	usort( $episodes, 'lkc_compare_episode_order' );
	set_transient( $cache_key, array_map( 'absint', wp_list_pluck( $episodes, 'ID' ) ), 12 * HOUR_IN_SECONDS );

	return $episodes;
}

/** Invalidate cached episode ordering when episode content changes. */
function lkc_bump_episode_order_cache_version() {
	$version = max( 1, absint( get_option( 'lkc_episode_order_cache_version', 1 ) ) );
	update_option( 'lkc_episode_order_cache_version', $version + 1, false );
}
add_action( 'save_post_lk_episode', 'lkc_bump_episode_order_cache_version', 20 );

function lkc_bump_episode_order_cache_version_on_delete( $post_id, $post ) {
	if ( $post instanceof WP_Post && 'lk_episode' === $post->post_type ) {
		lkc_bump_episode_order_cache_version();
	}
}
add_action( 'deleted_post', 'lkc_bump_episode_order_cache_version_on_delete', 10, 2 );

/** Render ordered episode cards for the current catalog entry. */
function lkc_episode_list_shortcode( $atts ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_episode_list' );
	$post_id = absint( $atts['id'] );
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	if ( ! $post_id || 'lk_title' !== get_post_type( $post_id ) ) {
		return '';
	}

	$episodes = lkc_get_ordered_episodes_for_title( $post_id );

	$heading_id = 'lk-episode-heading-' . $post_id;
	$output     = '<section class="lk-episode-section" aria-labelledby="' . esc_attr( $heading_id ) . '">';
	$output    .= '<div class="lk-episode-heading"><p class="lk-section-eyebrow">' . esc_html__( 'TONTON BERURUTAN', 'layar-katalog-core' ) . '</p>';
	$output    .= '<h2 id="' . esc_attr( $heading_id ) . '">' . esc_html__( 'Daftar episode', 'layar-katalog-core' ) . '</h2></div>';

	if ( empty( $episodes ) ) {
		$output .= '<p class="lk-empty-note">' . esc_html__( 'Episode belum ditambahkan.', 'layar-katalog-core' ) . '</p></section>';
		return $output;
	}

	$output .= '<ol class="lk-episodes">';
	foreach ( $episodes as $episode ) {
		$season  = max( 1, (int) get_post_meta( $episode->ID, '_lk_season', true ) );
		$number_raw   = get_post_meta( $episode->ID, '_lk_episode_number', true );
		$runtime      = absint( get_post_meta( $episode->ID, '_lk_runtime', true ) );
		$number_label = lkc_format_episode_number( $number_raw );
		$url = get_permalink( $episode );
		$output .= '<li class="lk-episode-item"><a class="lk-episode-link" href="' . esc_url( $url ) . '">';
		$output .= '<span class="lk-episode-play" aria-hidden="true">▶</span>';
		$output .= '<span class="lk-episode-copy"><span class="lk-episode-index">' . esc_html( sprintf( __( 'Musim %1$d · Episode %2$s', 'layar-katalog-core' ), $season, $number_label ) ) . '</span>';
		$output .= '<span class="lk-episode-title">' . esc_html( get_the_title( $episode ) ) . '</span></span>';
		if ( $runtime ) {
			$output .= '<span class="lk-episode-runtime">' . esc_html( sprintf( __( '%d menit', 'layar-katalog-core' ), $runtime ) ) . '</span>';
		}
		$output .= '</a></li>';
	}
	$output .= '</ol></section>';
	return $output;
}
add_shortcode( 'lk_episode_list', 'lkc_episode_list_shortcode' );

/** Register the small, accessible source switcher only when an episode has mirrors. */
function lkc_register_frontend_assets() {
	wp_register_script(
		'lkc-player',
		plugin_dir_url( __FILE__ ) . 'player.js',
		array(),
		'1.4.0',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'lkc_register_frontend_assets' );

/** Render safe native HTML5 playback with up to three direct MP4/WebM sources. */
function lkc_player_shortcode( $atts = array() ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_player' );
	$post_id = absint( $atts['id'] );
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	if ( ! $post_id || 'lk_episode' !== get_post_type( $post_id ) ) {
		return '';
	}

	$sources = array();
	for ( $index = 1; $index <= 3; $index++ ) {
		$key = ( 1 === $index ) ? '_lk_video_url' : '_lk_video_url_' . $index;
		$url = lkc_sanitize_video_url( get_post_meta( $post_id, $key, true ) );
		if ( ! $url || in_array( $url, wp_list_pluck( $sources, 'url' ), true ) ) {
			continue;
		}
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$sources[] = array(
			'url'   => $url,
			'type'  => ( 'webm' === $extension ) ? 'video/webm' : 'video/mp4',
			'label' => sprintf( __( 'Sumber %d', 'layar-katalog-core' ), $index ),
		);
	}
	if ( empty( $sources ) ) {
		return '<div class="lk-player lk-player-empty"><p>' . esc_html__( 'Video belum tersedia untuk episode ini.', 'layar-katalog-core' ) . '</p></div>';
	}

	if ( count( $sources ) > 1 ) {
		wp_enqueue_script( 'lkc-player' );
	}
	$player_id  = 'lk-player-' . $post_id;
	$poster_id  = get_post_thumbnail_id( $post_id );
	$poster_url = $poster_id ? wp_get_attachment_image_url( $poster_id, 'large' ) : '';
	$first      = $sources[0];
	$output     = '<section id="' . esc_attr( $player_id ) . '" class="lk-player" data-lk-player>';

	if ( count( $sources ) > 1 ) {
		$output .= '<div class="lk-player__source-switch" role="group" aria-label="' . esc_attr__( 'Pilih sumber video', 'layar-katalog-core' ) . '">';
		foreach ( $sources as $index => $source ) {
			$output .= '<button type="button" class="lk-player__source' . ( 0 === $index ? ' is-current' : '' ) . '" data-video-src="' . esc_url( $source['url'] ) . '" data-video-type="' . esc_attr( $source['type'] ) . '" aria-pressed="' . ( 0 === $index ? 'true' : 'false' ) . '">' . esc_html( $source['label'] ) . '</button>';
		}
		$output .= '</div><p class="screen-reader-text" data-player-status aria-live="polite"></p>';
	}

	$output .= '<video class="lk-player__video" controls preload="metadata" playsinline' . ( $poster_url ? ' poster="' . esc_url( $poster_url ) . '"' : '' ) . ' aria-label="' . esc_attr( get_the_title( $post_id ) ) . '">';
	$output .= '<source src="' . esc_url( $first['url'] ) . '" type="' . esc_attr( $first['type'] ) . '">';
	$output .= esc_html__( 'Browser Anda tidak mendukung pemutar video HTML5.', 'layar-katalog-core' );
	$output .= '</video>';

	if ( count( $sources ) > 1 ) {
		$output .= '<noscript><ul class="lk-player__fallback">';
		foreach ( $sources as $source ) {
			$output .= '<li><a href="' . esc_url( $source['url'] ) . '">' . esc_html( $source['label'] ) . '</a></li>';
		}
		$output .= '</ul></noscript>';
	}
	$output .= '</section>';
	return $output;
}
add_shortcode( 'lk_player', 'lkc_player_shortcode' );

/** Episode context and link back to its parent catalog item. */
function lkc_episode_info_shortcode( $atts = array() ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_episode_info' );
	$post_id = absint( $atts['id'] );
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	if ( ! $post_id || 'lk_episode' !== get_post_type( $post_id ) ) {
		return '';
	}
	$parent_id = absint( get_post_meta( $post_id, '_lk_parent_title', true ) );
	$season       = max( 1, (int) get_post_meta( $post_id, '_lk_season', true ) );
	$number_raw   = get_post_meta( $post_id, '_lk_episode_number', true );
	$runtime      = absint( get_post_meta( $post_id, '_lk_runtime', true ) );
	$number_label = lkc_format_episode_number( $number_raw );

	$items = array(
		sprintf( __( 'Musim %1$d · Episode %2$s', 'layar-katalog-core' ), $season, $number_label ),
	);
	if ( $runtime ) {
		$items[] = sprintf( __( '%d menit', 'layar-katalog-core' ), $runtime );
	}
	$output = '<div class="lk-episode-info"><p>' . esc_html( implode( '  •  ', $items ) ) . '</p>';
	if ( $parent_id && 'publish' === get_post_status( $parent_id ) ) {
		$output .= '<p><a class="lk-back-to-title" href="' . esc_url( get_permalink( $parent_id ) ) . '">← ' . esc_html( sprintf( __( 'Kembali ke %s', 'layar-katalog-core' ), get_the_title( $parent_id ) ) ) . '</a></p>';
	}
	$output .= '</div>';
	return $output;
}
add_shortcode( 'lk_episode_info', 'lkc_episode_info_shortcode' );

/** Render visible breadcrumbs for catalog and episode pages. */
function lkc_breadcrumbs_shortcode( $atts = array() ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_breadcrumbs' );
	$post_id = absint( $atts['id'] );
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	$post_type = $post_id ? get_post_type( $post_id ) : '';
	if ( ! $post_id || ! in_array( $post_type, array( 'lk_title', 'lk_episode' ), true ) ) {
		return '';
	}

	$catalog_url = get_post_type_archive_link( 'lk_title' );
	if ( ! $catalog_url ) {
		$catalog_url = home_url( '/?post_type=lk_title' );
	}
	$items = array(
		array( 'label' => __( 'Beranda', 'layar-katalog-core' ), 'url' => home_url( '/' ) ),
	);
	if ( 'lk_title' === $post_type ) {
		$items[] = array( 'label' => __( 'Katalog', 'layar-katalog-core' ), 'url' => $catalog_url );
	} else {
		$parent_id = absint( get_post_meta( $post_id, '_lk_parent_title', true ) );
		if ( $parent_id && 'publish' === get_post_status( $parent_id ) ) {
			$items[] = array( 'label' => __( 'Katalog', 'layar-katalog-core' ), 'url' => $catalog_url );
			$items[] = array( 'label' => get_the_title( $parent_id ), 'url' => get_permalink( $parent_id ) );
		} else {
			$episode_url = get_post_type_archive_link( 'lk_episode' );
			if ( $episode_url ) {
				$items[] = array( 'label' => __( 'Episode terbaru', 'layar-katalog-core' ), 'url' => $episode_url );
			}
		}
	}
	$items[] = array( 'label' => get_the_title( $post_id ), 'url' => '' );

	$output = '<nav class="lk-breadcrumbs" aria-label="' . esc_attr__( 'Jejak navigasi', 'layar-katalog-core' ) . '"><ol>';
	$last   = count( $items ) - 1;
	foreach ( $items as $index => $item ) {
		$output .= '<li' . ( $index === $last ? ' aria-current="page"' : '' ) . '>';
		if ( $item['url'] && $index !== $last ) {
			$output .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
		} else {
			$output .= '<span>' . esc_html( $item['label'] ) . '</span>';
		}
		$output .= '</li>';
	}
	$output .= '</ol></nav>';
	return $output;
}
add_shortcode( 'lk_breadcrumbs', 'lkc_breadcrumbs_shortcode' );

/** Choose an explicitly featured title for the home hero, then fall back to newest. */
function lkc_featured_title_shortcode( $atts = array() ) {
	$items = get_posts(
		array(
			'post_type'              => 'lk_title',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'meta_key'               => '_lk_featured',
			'meta_value'             => '1',
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	$is_featured = ! empty( $items );
	if ( ! $items ) {
		$items = get_posts(
			array(
				'post_type'              => 'lk_title',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}
	if ( ! $items ) {
		return '<div class="hero-feature-card lk-featured-title lk-featured-title--empty"><p>' . esc_html__( 'Tambahkan item katalog untuk menampilkan pilihan editor.', 'layar-katalog-core' ) . '</p></div>';
	}
	$item  = $items[0];
	$url   = get_permalink( $item );
	$title = get_the_title( $item );
	$date  = get_post_time( 'c', false, $item );
	$output  = '<article class="hero-feature-card lk-featured-title">';
	$output .= '<a class="hero-feature-image" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $title ) . '">';
	if ( has_post_thumbnail( $item ) ) {
		$output .= get_the_post_thumbnail(
			$item,
			'large',
			array(
				'loading'       => 'eager',
				'fetchpriority' => 'high',
				'decoding'      => 'async',
			)
		);
	}
	$output .= '</a><div class="hero-feature-copy"><p class="hero-feature-kicker">' . esc_html( $is_featured ? __( 'Pilihan editor', 'layar-katalog-core' ) : __( 'Katalog terbaru', 'layar-katalog-core' ) ) . '</p>';
	$output .= '<h2 class="hero-feature-title"><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h2>';
	$output .= '<time class="hero-feature-date" datetime="' . esc_attr( $date ) . '">' . esc_html( get_the_date( 'd M Y', $item ) ) . '</time></div></article>';
	return $output;
}
add_shortcode( 'lk_featured_title', 'lkc_featured_title_shortcode' );

function lkc_request_value( $key ) {
	if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
		return '';
	}
	return wp_unslash( (string) $_GET[ $key ] );
}

/** Cache the distinct release-year options shared by catalog filters. */
function lkc_get_catalog_years() {
	$cached = get_transient( 'lkc_catalog_years' );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$years = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) AS release_year
			FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = %s
			AND CAST(pm.meta_value AS UNSIGNED) BETWEEN 1888 AND 2100
			ORDER BY release_year DESC LIMIT 100",
			'_lk_year',
			'lk_title',
			'publish'
		)
	);
	$years = is_array( $years ) ? array_map( 'absint', $years ) : array();
	$years = array_values( array_unique( array_filter( $years, function( $year ) {
		return $year >= 1888 && $year <= 2100;
	} ) ) );
	rsort( $years, SORT_NUMERIC );
	set_transient( 'lkc_catalog_years', $years, 6 * HOUR_IN_SECONDS );

	return $years;
}

/** Invalidate filter options after a catalog title is added, edited, or unpublished. */
function lkc_invalidate_catalog_years_cache() {
	delete_transient( 'lkc_catalog_years' );
}
add_action( 'save_post_lk_title', 'lkc_invalidate_catalog_years_cache', 20 );

function lkc_invalidate_catalog_years_cache_on_delete( $post_id, $post ) {
	if ( $post instanceof WP_Post && 'lk_title' === $post->post_type ) {
		lkc_invalidate_catalog_years_cache();
	}
}
add_action( 'deleted_post', 'lkc_invalidate_catalog_years_cache_on_delete', 10, 2 );

/** Native GET controls for faceted catalog browsing; works without JavaScript. */
function lkc_catalog_filters_shortcode( $atts = array() ) {
	$catalog_url = get_post_type_archive_link( 'lk_title' );
	if ( ! $catalog_url ) {
		return '';
	}
	$selected = array(
		'q'      => sanitize_text_field( lkc_request_value( 'lk_q' ) ),
		'genre'  => sanitize_title( lkc_request_value( 'lk_genre' ) ),
		'format' => lkc_sanitize_format( lkc_request_value( 'lk_format' ) ),
		'year'   => lkc_sanitize_year( lkc_request_value( 'lk_year' ) ),
		'status' => lkc_sanitize_status( lkc_request_value( 'lk_status' ) ),
		'sort'   => sanitize_key( lkc_request_value( 'lk_sort' ) ),
	);
	if ( ! $selected['genre'] && is_tax( 'lk_genre' ) ) {
		$current_genre = get_queried_object();
		if ( $current_genre && ! is_wp_error( $current_genre ) && ! empty( $current_genre->slug ) ) {
			$selected['genre'] = sanitize_title( $current_genre->slug );
		}
	}
	$selected['q'] = wp_html_excerpt( $selected['q'], 100, '' );
	if ( ! in_array( $selected['sort'], array( 'newest', 'oldest', 'rating', 'title' ), true ) ) {
		$selected['sort'] = 'newest';
	}

	$genres = get_terms(
		array(
			'taxonomy'   => 'lk_genre',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	$genres = is_wp_error( $genres ) ? array() : $genres;

	$years = lkc_get_catalog_years();

	$formats = array(
		''          => __( 'Semua format', 'layar-katalog-core' ),
		'animation' => __( 'Animasi', 'layar-katalog-core' ),
		'film'      => __( 'Film', 'layar-katalog-core' ),
		'series'    => __( 'Serial', 'layar-katalog-core' ),
		'other'     => __( 'Lainnya', 'layar-katalog-core' ),
	);
	$statuses = array(
		''          => __( 'Semua status', 'layar-katalog-core' ),
		'upcoming'  => __( 'Akan datang', 'layar-katalog-core' ),
		'ongoing'   => __( 'Berjalan', 'layar-katalog-core' ),
		'completed' => __( 'Selesai', 'layar-katalog-core' ),
	);

	$output  = '<form class="lk-catalog-filter" method="get" action="' . esc_url( $catalog_url ) . '" role="search">';
	$output .= '<div class="lk-filter-field lk-filter-search"><label for="lk-filter-q">' . esc_html__( 'Kata kunci', 'layar-katalog-core' ) . '</label><input id="lk-filter-q" type="search" name="lk_q" value="' . esc_attr( $selected['q'] ) . '" placeholder="' . esc_attr__( 'Cari judul katalog…', 'layar-katalog-core' ) . '"></div>';
	$output .= '<div class="lk-filter-field"><label for="lk-filter-genre">' . esc_html__( 'Genre', 'layar-katalog-core' ) . '</label><select id="lk-filter-genre" name="lk_genre"><option value="">' . esc_html__( 'Semua genre', 'layar-katalog-core' ) . '</option>';
	foreach ( $genres as $genre ) {
		$output .= '<option value="' . esc_attr( $genre->slug ) . '" ' . selected( $selected['genre'], $genre->slug, false ) . '>' . esc_html( $genre->name ) . '</option>';
	}
	$output .= '</select></div>';
	$output .= '<div class="lk-filter-field"><label for="lk-filter-format">' . esc_html__( 'Format', 'layar-katalog-core' ) . '</label><select id="lk-filter-format" name="lk_format">';
	foreach ( $formats as $value => $label ) {
		$output .= '<option value="' . esc_attr( $value ) . '" ' . selected( $selected['format'], $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$output .= '</select></div>';
	$output .= '<div class="lk-filter-field"><label for="lk-filter-year">' . esc_html__( 'Tahun', 'layar-katalog-core' ) . '</label><select id="lk-filter-year" name="lk_year"><option value="0">' . esc_html__( 'Semua tahun', 'layar-katalog-core' ) . '</option>';
	foreach ( $years as $year ) {
		$year = absint( $year );
		$output .= '<option value="' . esc_attr( $year ) . '" ' . selected( $selected['year'], $year, false ) . '>' . esc_html( (string) $year ) . '</option>';
	}
	$output .= '</select></div>';
	$output .= '<div class="lk-filter-field"><label for="lk-filter-status">' . esc_html__( 'Status', 'layar-katalog-core' ) . '</label><select id="lk-filter-status" name="lk_status">';
	foreach ( $statuses as $value => $label ) {
		$output .= '<option value="' . esc_attr( $value ) . '" ' . selected( $selected['status'], $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$output .= '</select></div>';
	$output .= '<div class="lk-filter-field"><label for="lk-filter-sort">' . esc_html__( 'Urutkan', 'layar-katalog-core' ) . '</label><select id="lk-filter-sort" name="lk_sort">';
	$sort_options = array(
		'newest' => __( 'Terbaru', 'layar-katalog-core' ),
		'oldest' => __( 'Terlama', 'layar-katalog-core' ),
		'rating' => __( 'Rating editorial', 'layar-katalog-core' ),
		'title'  => __( 'Judul A–Z', 'layar-katalog-core' ),
	);
	foreach ( $sort_options as $value => $label ) {
		$output .= '<option value="' . esc_attr( $value ) . '" ' . selected( $selected['sort'], $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$output .= '</select></div><div class="lk-filter-actions"><button type="submit">' . esc_html__( 'Terapkan filter', 'layar-katalog-core' ) . '</button><a href="' . esc_url( $catalog_url ) . '">' . esc_html__( 'Reset', 'layar-katalog-core' ) . '</a></div></form>';
	return $output;
}
add_shortcode( 'lk_catalog_filters', 'lkc_catalog_filters_shortcode' );

/** Show real, populated genre links instead of hard-coded dead links. */
function lkc_genre_links_shortcode( $atts = array() ) {
	$atts   = shortcode_atts( array( 'limit' => 12 ), $atts, 'lk_genre_links' );
	$limit  = max( 1, min( 30, absint( $atts['limit'] ) ) );
	$genres = get_terms(
		array(
			'taxonomy'   => 'lk_genre',
			'hide_empty' => true,
			'number'     => $limit,
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);
	if ( is_wp_error( $genres ) || empty( $genres ) ) {
		return '<p class="lk-empty-note">' . esc_html__( 'Tambahkan genre pada item katalog untuk menampilkan tautan jelajah.', 'layar-katalog-core' ) . '</p>';
	}
	$output = '<nav class="lk-genre-links" aria-label="' . esc_attr__( 'Jelajahi genre', 'layar-katalog-core' ) . '"><ul>';
	foreach ( $genres as $genre ) {
		$url = get_term_link( $genre );
		if ( is_wp_error( $url ) ) {
			continue;
		}
		$output .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $genre->name ) . '<span>' . esc_html( number_format_i18n( $genre->count ) ) . '</span></a></li>';
	}
	$output .= '</ul></nav>';
	return $output;
}
add_shortcode( 'lk_genre_links', 'lkc_genre_links_shortcode' );

/** Give a mixed site-search result the right catalog/episode context. */
function lkc_search_result_details_shortcode( $atts = array() ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_search_result_details' );
	$post_id = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();
	if ( 'lk_title' === get_post_type( $post_id ) ) {
		return lkc_catalog_details_shortcode( array( 'id' => $post_id ) );
	}
	if ( 'lk_episode' === get_post_type( $post_id ) ) {
		return lkc_episode_info_shortcode( array( 'id' => $post_id ) );
	}
	return '';
}
add_shortcode( 'lk_search_result_details', 'lkc_search_result_details_shortcode' );

/** Return related catalog entries from shared genres, with a useful fallback. */
function lkc_related_titles_shortcode( $atts = array() ) {
	$atts    = shortcode_atts( array( 'id' => 0, 'limit' => 4 ), $atts, 'lk_related_titles' );
	$post_id = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();
	if ( ! $post_id || 'lk_title' !== get_post_type( $post_id ) ) {
		return '';
	}
	$limit = max( 1, min( 8, absint( $atts['limit'] ) ) );
	$args  = array(
		'post_type'              => 'lk_title',
		'post_status'            => 'publish',
		'posts_per_page'         => $limit,
		'post__not_in'           => array( $post_id ),
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => true,
	);
	$genres = wp_get_object_terms( $post_id, 'lk_genre', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'lk_genre',
				'field'    => 'term_id',
				'terms'    => $genres,
			),
		);
	}
	$related = get_posts( $args );
	if ( empty( $related ) ) {
		return '';
	}
	$heading_id = 'lk-related-heading-' . $post_id;
	$output     = '<section class="lk-related-titles" aria-labelledby="' . esc_attr( $heading_id ) . '"><h2 id="' . esc_attr( $heading_id ) . '">' . esc_html__( 'Jelajahi judul terkait', 'layar-katalog-core' ) . '</h2><ul>';
	foreach ( $related as $item ) {
		$output .= '<li><article class="lk-related-card">';
		$output .= '<a class="lk-related-card__image" href="' . esc_url( get_permalink( $item ) ) . '" aria-label="' . esc_attr( get_the_title( $item ) ) . '">';
		if ( has_post_thumbnail( $item ) ) {
			$output .= get_the_post_thumbnail( $item, 'medium', array( 'loading' => 'lazy' ) );
		} else {
			$output .= '<span aria-hidden="true">' . esc_html__( 'Tanpa gambar', 'layar-katalog-core' ) . '</span>';
		}
		$output .= '</a><h3><a href="' . esc_url( get_permalink( $item ) ) . '">' . esc_html( get_the_title( $item ) ) . '</a></h3>';
		$output .= lkc_catalog_details_shortcode( array( 'id' => $item->ID ) ) . '</article></li>';
	}
	$output .= '</ul></section>';
	return $output;
}
add_shortcode( 'lk_related_titles', 'lkc_related_titles_shortcode' );

/** Previous/next episode links follow the same season and episode ordering as the list. */
function lkc_episode_navigation_shortcode( $atts = array() ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_episode_navigation' );
	$post_id = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();
	if ( ! $post_id || 'lk_episode' !== get_post_type( $post_id ) ) {
		return '';
	}
	$parent_id = absint( get_post_meta( $post_id, '_lk_parent_title', true ) );
	if ( ! $parent_id ) {
		return '';
	}
	$episodes = lkc_get_ordered_episodes_for_title( $parent_id );
	$current_index = null;
	foreach ( $episodes as $index => $episode ) {
		if ( $post_id === (int) $episode->ID ) {
			$current_index = $index;
			break;
		}
	}
	if ( null === $current_index ) {
		return '';
	}
	$previous = ( $current_index > 0 ) ? $episodes[ $current_index - 1 ] : null;
	$next     = ( $current_index < count( $episodes ) - 1 ) ? $episodes[ $current_index + 1 ] : null;
	$output   = '<nav class="lk-episode-navigation" aria-label="' . esc_attr__( 'Navigasi episode', 'layar-katalog-core' ) . '">';
	if ( $previous ) {
		$output .= '<a class="lk-episode-navigation__previous" rel="prev" href="' . esc_url( get_permalink( $previous ) ) . '"><span>' . esc_html__( 'Episode sebelumnya', 'layar-katalog-core' ) . '</span><strong>' . esc_html( get_the_title( $previous ) ) . '</strong></a>';
	} else {
		$output .= '<span class="lk-episode-navigation__placeholder"></span>';
	}
	$output .= '<a class="lk-episode-navigation__series" href="' . esc_url( get_permalink( $parent_id ) ) . '">' . esc_html__( 'Semua episode', 'layar-katalog-core' ) . '</a>';
	if ( $next ) {
		$output .= '<a class="lk-episode-navigation__next" rel="next" href="' . esc_url( get_permalink( $next ) ) . '"><span>' . esc_html__( 'Episode berikutnya', 'layar-katalog-core' ) . '</span><strong>' . esc_html( get_the_title( $next ) ) . '</strong></a>';
	} else {
		$output .= '<span class="lk-episode-navigation__placeholder"></span>';
	}
	$output .= '</nav>';
	return $output;
}
add_shortcode( 'lk_episode_navigation', 'lkc_episode_navigation_shortcode' );

/** Apply public catalog search/facets before the main query is run. */
function lkc_add_public_query_vars( $vars ) {
	return array_merge( $vars, array( 'lk_q', 'lk_genre', 'lk_format', 'lk_year', 'lk_status', 'lk_sort' ) );
}
add_filter( 'query_vars', 'lkc_add_public_query_vars' );

function lkc_filter_catalog_queries( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( $query->is_search() ) {
		$query->set( 'post_type', array( 'post', 'page', 'lk_title', 'lk_episode' ) );
		$query->set( 'posts_per_page', 12 );
		return;
	}
	if ( ! $query->is_post_type_archive( 'lk_title' ) && ! $query->is_tax( 'lk_genre' ) ) {
		return;
	}

	$query->set( 'post_type', 'lk_title' );
	$query->set( 'posts_per_page', 12 );
	$raw_search = $query->get( 'lk_q' );
	$search     = is_scalar( $raw_search ) ? sanitize_text_field( (string) $raw_search ) : '';
	if ( $search ) {
		$query->set( 's', wp_html_excerpt( $search, 100, '' ) );
	}

	$tax_query = $query->get( 'tax_query' );
	$tax_query = is_array( $tax_query ) ? $tax_query : array();
	$raw_genre = $query->get( 'lk_genre' );
	$genre     = is_scalar( $raw_genre ) ? sanitize_title( (string) $raw_genre ) : '';
	if ( $genre ) {
		$tax_query[] = array(
			'taxonomy' => 'lk_genre',
			'field'    => 'slug',
			'terms'    => $genre,
		);
	}
	if ( ! empty( $tax_query ) ) {
		$query->set( 'tax_query', $tax_query );
	}

	$meta_query = $query->get( 'meta_query' );
	$meta_query = is_array( $meta_query ) ? $meta_query : array();
	$format     = lkc_sanitize_format( $query->get( 'lk_format' ) );
	$year       = lkc_sanitize_year( $query->get( 'lk_year' ) );
	$status     = lkc_sanitize_status( $query->get( 'lk_status' ) );
	if ( $format ) {
		$meta_query[] = array( 'key' => '_lk_format', 'value' => $format, 'compare' => '=' );
	}
	if ( $year ) {
		$meta_query[] = array( 'key' => '_lk_year', 'value' => $year, 'compare' => '=', 'type' => 'NUMERIC' );
	}
	if ( $status ) {
		$meta_query[] = array( 'key' => '_lk_status', 'value' => $status, 'compare' => '=' );
	}
	if ( ! empty( $meta_query ) ) {
		$query->set( 'meta_query', $meta_query );
	}

	$raw_sort = $query->get( 'lk_sort' );
	$sort     = is_scalar( $raw_sort ) ? sanitize_key( (string) $raw_sort ) : '';
	if ( 'rating' === $sort ) {
		$query->set( 'meta_key', '_lk_rating' );
		$query->set( 'orderby', array( 'meta_value_num' => 'DESC', 'date' => 'DESC' ) );
	} elseif ( 'title' === $sort ) {
		$query->set( 'orderby', 'title' );
		$query->set( 'order', 'ASC' );
	} elseif ( 'oldest' === $sort ) {
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'ASC' );
	} else {
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );
	}
}
add_action( 'pre_get_posts', 'lkc_filter_catalog_queries', 20 );

/** Register contextual dynamic blocks so metadata works inside Query Loops. */
function lkc_register_context_blocks() {
	$script_path = plugin_dir_path( __FILE__ ) . 'blocks.js';
	$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : '1.4.0';
	wp_register_script(
		'lkc-block-editor',
		plugin_dir_url( __FILE__ ) . 'blocks.js',
		array( 'wp-blocks', 'wp-element' ),
		$version,
		true
	);

	$definitions = array(
		'breadcrumbs' => array(
			'title'       => __( 'Breadcrumb Katalog', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan jejak navigasi untuk item katalog atau episode.', 'layar-katalog-core' ),
			'icon'        => 'list-view',
			'shortcode'   => 'lk_breadcrumbs',
			'post_type'   => 'any',
		),
		'catalog-details' => array(
			'title'     => __( 'Metadata Katalog', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan format, tahun, status, dan rating editorial item katalog.', 'layar-katalog-core' ),
			'icon'      => 'info-outline',
			'shortcode' => 'lk_catalog_details',
			'post_type' => 'lk_title',
		),
		'episode-list' => array(
			'title'     => __( 'Daftar Episode', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan episode milik item katalog saat ini.', 'layar-katalog-core' ),
			'icon'      => 'list-view',
			'shortcode' => 'lk_episode_list',
			'post_type' => 'lk_title',
		),
		'episode-info' => array(
			'title'     => __( 'Info Episode', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan musim, nomor, durasi, dan tautan kembali.', 'layar-katalog-core' ),
			'icon'      => 'info-outline',
			'shortcode' => 'lk_episode_info',
			'post_type' => 'lk_episode',
		),
		'video-player' => array(
			'title'     => __( 'Pemutar Video', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan pemutar HTML5 untuk video MP4/WebM episode saat ini.', 'layar-katalog-core' ),
			'icon'      => 'format-video',
			'shortcode' => 'lk_player',
			'post_type' => 'lk_episode',
		),
		'featured-title' => array(
			'title'       => __( 'Pilihan Editor', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan judul pilihan editor; jika belum ada, memakai judul terbaru.', 'layar-katalog-core' ),
			'icon'        => 'star-filled',
			'shortcode'   => 'lk_featured_title',
			'post_type'   => 'any',
		),
		'catalog-filters' => array(
			'title'       => __( 'Filter Katalog', 'layar-katalog-core' ),
			'description' => __( 'Filter judul menurut genre, format, tahun, status, dan urutan.', 'layar-katalog-core' ),
			'icon'        => 'filter',
			'shortcode'   => 'lk_catalog_filters',
			'post_type'   => 'any',
		),
		'genre-links' => array(
			'title'       => __( 'Jelajah Genre', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan genre yang sudah dipakai pada katalog.', 'layar-katalog-core' ),
			'icon'        => 'tag',
			'shortcode'   => 'lk_genre_links',
			'post_type'   => 'any',
		),
		'search-result-details' => array(
			'title'       => __( 'Konteks Hasil Pencarian', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan metadata yang sesuai untuk hasil katalog atau episode.', 'layar-katalog-core' ),
			'icon'        => 'search',
			'shortcode'   => 'lk_search_result_details',
			'post_type'   => 'any',
		),
		'related-titles' => array(
			'title'       => __( 'Judul Terkait', 'layar-katalog-core' ),
			'description' => __( 'Menampilkan judul lain dengan genre yang sama.', 'layar-katalog-core' ),
			'icon'        => 'screenoptions',
			'shortcode'   => 'lk_related_titles',
			'post_type'   => 'lk_title',
		),
		'episode-navigation' => array(
			'title'       => __( 'Navigasi Episode', 'layar-katalog-core' ),
			'description' => __( 'Tautan episode sebelumnya/berikutnya dan kembali ke katalog induk.', 'layar-katalog-core' ),
			'icon'        => 'controls-play',
			'shortcode'   => 'lk_episode_navigation',
			'post_type'   => 'lk_episode',
		),
	);

	foreach ( $definitions as $slug => $definition ) {
		$shortcode = $definition['shortcode'];
		$post_type = $definition['post_type'];
		$callback  = function( $attributes, $content, $block ) use ( $shortcode, $post_type ) {
			$post_id = ( isset( $block->context['postId'] ) ) ? absint( $block->context['postId'] ) : 0;
			if ( ! $post_id ) {
				$post_id = get_the_ID();
			}
			if ( ! $post_id || ( 'any' !== $post_type && $post_type !== get_post_type( $post_id ) ) ) {
				return '';
			}
			return do_shortcode( '[' . $shortcode . ' id="' . absint( $post_id ) . '"]' );
		};

		register_block_type(
			'layar-katalog/' . $slug,
			array(
				'api_version'     => 3,
				'title'           => $definition['title'],
				'description'     => $definition['description'],
				'category'        => 'widgets',
				'icon'            => $definition['icon'],
				'uses_context'    => array( 'postId', 'postType' ),
				'supports'        => array( 'html' => false, 'inserter' => false ),
				'editor_script'   => 'lkc-block-editor',
				'render_callback' => $callback,
			)
		);
	}
}
add_action( 'init', 'lkc_register_context_blocks', 20 );

/** Useful columns in the admin lists. */
function lkc_catalog_admin_columns( $columns ) {
	$columns['lk_year']   = __( 'Tahun', 'layar-katalog-core' );
	$columns['lk_rating'] = __( 'Rating', 'layar-katalog-core' );
	$columns['lk_status'] = __( 'Status', 'layar-katalog-core' );
	return $columns;
}
add_filter( 'manage_lk_title_posts_columns', 'lkc_catalog_admin_columns' );

function lkc_catalog_admin_column_content( $column, $post_id ) {
	if ( 'lk_year' === $column ) {
		echo esc_html( get_post_meta( $post_id, '_lk_year', true ) );
	} elseif ( 'lk_rating' === $column ) {
		$rating = get_post_meta( $post_id, '_lk_rating', true );
		echo $rating ? esc_html( number_format_i18n( (float) $rating, 1 ) . ' / 10' ) : '—';
	} elseif ( 'lk_status' === $column ) {
		$labels = array( 'upcoming' => __( 'Akan datang', 'layar-katalog-core' ), 'ongoing' => __( 'Berjalan', 'layar-katalog-core' ), 'completed' => __( 'Selesai', 'layar-katalog-core' ) );
		$status = get_post_meta( $post_id, '_lk_status', true );
		echo isset( $labels[ $status ] ) ? esc_html( $labels[ $status ] ) : '—';
	}
}
add_action( 'manage_lk_title_posts_custom_column', 'lkc_catalog_admin_column_content', 10, 2 );

function lkc_episode_admin_columns( $columns ) {
	$columns['lk_parent']   = __( 'Item Katalog', 'layar-katalog-core' );
	$columns['lk_ep_number'] = __( 'Episode', 'layar-katalog-core' );
	$columns['lk_video']    = __( 'Video', 'layar-katalog-core' );
	return $columns;
}
add_filter( 'manage_lk_episode_posts_columns', 'lkc_episode_admin_columns' );

function lkc_episode_admin_column_content( $column, $post_id ) {
	if ( 'lk_parent' === $column ) {
		$parent_id = absint( get_post_meta( $post_id, '_lk_parent_title', true ) );
		echo $parent_id ? esc_html( get_the_title( $parent_id ) ) : '—';
	} elseif ( 'lk_ep_number' === $column ) {
		$season = max( 1, (int) get_post_meta( $post_id, '_lk_season', true ) );
		$number = get_post_meta( $post_id, '_lk_episode_number', true );
		echo esc_html( sprintf( 'S%02d · E%s', $season, $number ) );
	} elseif ( 'lk_video' === $column ) {
		echo get_post_meta( $post_id, '_lk_video_url', true ) ? esc_html__( 'URL diisi', 'layar-katalog-core' ) : esc_html__( 'Belum ada', 'layar-katalog-core' );
	}
}
add_action( 'manage_lk_episode_posts_custom_column', 'lkc_episode_admin_column_content', 10, 2 );

/** Small admin-only styling for the metabox form. */
function lkc_admin_styles( $hook ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->post_type, array( 'lk_title', 'lk_episode' ), true ) ) {
		return;
	}
	$css = '.lkc-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 20px}.lkc-admin-wide{grid-column:1/-1}@media(max-width:700px){.lkc-admin-grid{grid-template-columns:1fr}.lkc-admin-wide{grid-column:auto}}';
	wp_register_style( 'lkc-admin-inline', false, array(), '1.4.0' );
	wp_enqueue_style( 'lkc-admin-inline' );
	wp_add_inline_style( 'lkc-admin-inline', $css );
}
add_action( 'admin_enqueue_scripts', 'lkc_admin_styles' );

/** Detect popular SEO plugins and avoid duplicate titles, meta tags, and schema. */
function lkc_is_seo_plugin_active() {
	$detected  = false;
	$constants = array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION' );
	foreach ( $constants as $constant ) {
		if ( defined( $constant ) ) {
			$detected = true;
			break;
		}
	}

	if ( ! $detected ) {
		$classes = array( 'WPSEO_Frontend', 'RankMath\\Main', 'AIOSEO\\Plugin\\Common\\Main', 'The_SEO_Framework\\Load' );
		foreach ( $classes as $class_name ) {
			if ( class_exists( $class_name ) ) {
				$detected = true;
				break;
			}
		}
	}

	if ( ! $detected && defined( 'JETPACK__VERSION' ) ) {
		$detected = class_exists( 'Jetpack_SEO' ) || function_exists( 'jetpack_og_tags' );
	}

	return (bool) apply_filters( 'lkc_has_external_seo_plugin', $detected );
}

/** Use a custom SEO title only when a full SEO plugin is not active. */
function lkc_filter_document_title_parts( $title_parts ) {
	if ( is_admin() || lkc_is_seo_plugin_active() || ! is_singular( array( 'lk_title', 'lk_episode' ) ) ) {
		return $title_parts;
	}
	$post_id    = get_queried_object_id();
	$seo_title = $post_id ? get_post_meta( $post_id, '_lk_seo_title', true ) : '';
	if ( $seo_title ) {
		$title_parts['title'] = $seo_title;
	}
	return $title_parts;
}
add_filter( 'document_title_parts', 'lkc_filter_document_title_parts', 20 );

/** Strip markup and create a UTF-8-safe SEO snippet. */
function lkc_seo_plain_text( $value, $limit = 160 ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$text    = wp_strip_all_tags( (string) $value, true );
	$charset = get_bloginfo( 'charset' );
	$text    = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, $charset ? $charset : 'UTF-8' );
	$text    = preg_replace( '/\s+/u', ' ', trim( $text ) );
	return trim( wp_html_excerpt( $text, absint( $limit ), '…' ) );
}

function lkc_get_seo_description( $post_id = 0 ) {
	if ( $post_id ) {
		$custom = get_post_meta( $post_id, '_lk_seo_description', true );
		if ( $custom ) {
			return lkc_seo_plain_text( $custom, 160 );
		}
		$post = get_post( $post_id );
		$text = $post ? get_the_excerpt( $post ) : '';
		if ( ! $text && $post ) {
			$text = $post->post_content;
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$text = term_description();
	} elseif ( is_archive() ) {
		$text = get_the_archive_description();
	} elseif ( is_search() ) {
		$text = sprintf( __( 'Hasil pencarian untuk: %s', 'layar-katalog-core' ), get_search_query() );
	} else {
		$text = get_bloginfo( 'description' );
	}

	if ( ! $text ) {
		$text = get_bloginfo( 'description' );
	}
	if ( ! $text && ( is_front_page() || is_home() ) ) {
		$text = sprintf( __( 'Jelajahi katalog film, animasi, serial, dan ulasan pilihan di %s.', 'layar-katalog-core' ), get_bloginfo( 'name' ) );
	}
	if ( ! $text ) {
		$text = wp_get_document_title();
	}
	return lkc_seo_plain_text( $text, 160 );
}

function lkc_get_seo_image( $post_id = 0 ) {
	if ( $post_id && has_post_thumbnail( $post_id ) ) {
		$image = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $image ) {
			return $image;
		}
	}
	$site_icon = get_site_icon_url( 512 );
	return $site_icon ? $site_icon : '';
}

/** Output a useful baseline for social previews when no SEO plugin is active. */
function lkc_output_seo_meta() {
	if ( is_admin() || is_feed() || lkc_is_seo_plugin_active() ) {
		return;
	}

	$post_id = is_singular() ? get_queried_object_id() : 0;
	$title   = $post_id ? get_post_meta( $post_id, '_lk_seo_title', true ) : '';
	$title   = $title ? $title : wp_get_document_title();
	$desc    = lkc_get_seo_description( $post_id );
	$url     = $post_id ? get_permalink( $post_id ) : get_pagenum_link( max( 1, absint( get_query_var( 'paged' ) ) ) );
	$image   = lkc_get_seo_image( $post_id );
	$type    = ( $post_id && ! is_page( $post_id ) ) ? 'article' : 'website';
	$site    = get_bloginfo( 'name' );

	if ( ! $post_id && ( is_home() || is_archive() ) ) {
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	}
	if ( $desc ) {
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( $desc ) {
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	if ( $site ) {
		echo '<meta property="og:site_name" content="' . esc_attr( $site ) . '">' . "\n";
	}
	echo '<meta property="og:locale" content="' . esc_attr( get_locale() ) . '">' . "\n";
	if ( $image ) {
		echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
		if ( $post_id && has_post_thumbnail( $post_id ) ) {
			$image_alt = get_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true );
			echo '<meta property="og:image:alt" content="' . esc_attr( $image_alt ? $image_alt : $title ) . '">' . "\n";
		}
	}

	echo '<meta name="twitter:card" content="' . esc_attr( $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( $desc ) {
		echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	if ( $image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
	}

	if ( $post_id && in_array( get_post_type( $post_id ), array( 'post', 'lk_title', 'lk_episode' ), true ) ) {
		echo '<meta property="article:published_time" content="' . esc_attr( get_post_time( 'c', true, $post_id ) ) . '">' . "\n";
		echo '<meta property="article:modified_time" content="' . esc_attr( get_post_modified_time( 'c', true, $post_id ) ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'lkc_output_seo_meta', 2 );

/** Prevent faceted query-string combinations from becoming competing index URLs. */
function lkc_robots_for_filtered_catalog( $robots ) {
	if ( is_admin() || ! is_array( $robots ) || lkc_is_seo_plugin_active() || ! is_post_type_archive( 'lk_title' ) ) {
		return $robots;
	}
	foreach ( array( 'lk_q', 'lk_genre', 'lk_format', 'lk_year', 'lk_status', 'lk_sort' ) as $query_var ) {
		$value = get_query_var( $query_var );
		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			break;
		}
	}
	return $robots;
}
add_filter( 'wp_robots', 'lkc_robots_for_filtered_catalog' );

function lkc_get_breadcrumb_schema( $post_id ) {
	$post_type = get_post_type( $post_id );
	if ( ! in_array( $post_type, array( 'lk_title', 'lk_episode' ), true ) ) {
		return array();
	}

	$catalog_archive = get_post_type_archive_link( 'lk_title' );
	$items = array(
		array( 'name' => __( 'Beranda', 'layar-katalog-core' ), 'url' => home_url( '/' ) ),
		array( 'name' => __( 'Katalog', 'layar-katalog-core' ), 'url' => $catalog_archive ? $catalog_archive : home_url( '/katalog/' ) ),
	);
	if ( 'lk_episode' === $post_type ) {
		$parent_id = absint( get_post_meta( $post_id, '_lk_parent_title', true ) );
		if ( $parent_id && 'publish' === get_post_status( $parent_id ) ) {
			$items[] = array( 'name' => get_the_title( $parent_id ), 'url' => get_permalink( $parent_id ) );
		}
	}
	$items[] = array( 'name' => get_the_title( $post_id ), 'url' => get_permalink( $post_id ) );

	$list = array();
	foreach ( $items as $index => $item ) {
		$list[] = array(
			'@type'    => 'ListItem',
			'position' => $index + 1,
			'name'     => wp_strip_all_tags( $item['name'] ),
			'item'     => esc_url_raw( $item['url'] ),
		);
	}
	return array(
		'@context'        => 'https://schema.org',
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $list,
	);
}

/** Build conservative Schema.org data; editorial scores are not aggregate ratings. */
function lkc_get_structured_data() {
	$schemas = array();
	if ( is_front_page() || is_home() ) {
		$schemas[] = array(
			'@context'       => 'https://schema.org',
			'@type'          => 'WebSite',
			'@id'            => home_url( '/#website' ),
			'url'            => home_url( '/' ),
			'name'           => get_bloginfo( 'name' ),
			'description'    => lkc_get_seo_description(),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	$post_id = is_singular() ? get_queried_object_id() : 0;
	if ( $post_id && is_singular( 'lk_title' ) ) {
		$format = get_post_meta( $post_id, '_lk_format', true );
		$type   = ( 'film' === $format ) ? 'Movie' : ( ( 'series' === $format ) ? 'TVSeries' : 'CreativeWork' );
		$url    = get_permalink( $post_id );
		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => $type,
			'@id'             => $url . '#catalog-item',
			'url'             => $url,
			'name'            => get_the_title( $post_id ),
			'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $url ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'isPartOf'        => array( '@id' => home_url( '/#website' ) ),
		);
		$description = lkc_get_seo_description( $post_id );
		$image       = lkc_get_seo_image( $post_id );
		if ( $description ) {
			$schema['description'] = $description;
		}
		if ( $image ) {
			$schema['image'] = $image;
		}
		$genres = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $genres ) && ! empty( $genres ) ) {
			$schema['genre'] = array_values( $genres );
		}
		$schemas[] = $schema;
	} elseif ( $post_id && is_singular( 'lk_episode' ) ) {
		$url    = get_permalink( $post_id );
		$number = get_post_meta( $post_id, '_lk_episode_number', true );
		$season = max( 1, (int) get_post_meta( $post_id, '_lk_season', true ) );
		$runtime = absint( get_post_meta( $post_id, '_lk_runtime', true ) );
		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'TVEpisode',
			'@id'             => $url . '#episode',
			'url'             => $url,
			'name'            => get_the_title( $post_id ),
			'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $url ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'partOfSeason'    => array( '@type' => 'TVSeason', 'seasonNumber' => $season ),
		);
		if ( '' !== $number && is_numeric( $number ) ) {
			$schema['episodeNumber'] = (string) $number;
		}
		if ( $runtime ) {
			$schema['duration'] = 'PT' . $runtime . 'M';
		}
		$description = lkc_get_seo_description( $post_id );
		$image       = lkc_get_seo_image( $post_id );
		if ( $description ) {
			$schema['description'] = $description;
		}
		if ( $image ) {
			$schema['image'] = $image;
		}
		$parent_id = absint( get_post_meta( $post_id, '_lk_parent_title', true ) );
		if ( $parent_id && 'publish' === get_post_status( $parent_id ) ) {
			$parent_type = ( 'series' === get_post_meta( $parent_id, '_lk_format', true ) ) ? 'TVSeries' : 'CreativeWorkSeries';
			$schema['partOfSeries'] = array(
				'@type' => $parent_type,
				'@id'   => get_permalink( $parent_id ) . '#catalog-item',
				'url'   => get_permalink( $parent_id ),
				'name'  => get_the_title( $parent_id ),
			);
		}
		$schemas[] = $schema;
	}

	if ( $post_id && is_singular( array( 'lk_title', 'lk_episode' ) ) ) {
		$breadcrumb = lkc_get_breadcrumb_schema( $post_id );
		if ( $breadcrumb ) {
			$schemas[] = $breadcrumb;
		}
	}
	return $schemas;
}

function lkc_output_structured_data() {
	if ( is_admin() || lkc_is_seo_plugin_active() ) {
		return;
	}
	foreach ( lkc_get_structured_data() as $schema ) {
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( $json ) {
			echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
		}
	}
}
add_action( 'wp_head', 'lkc_output_structured_data', 3 );

/** Refresh rewrite rules once on upgrade so new archive/taxonomy routes work. */
function lkc_maybe_refresh_rewrite_rules() {
	$version = '1.3.0';
	if ( get_option( 'lkc_rewrite_version' ) === $version ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'lkc_rewrite_version', $version, false );
}
add_action( 'init', 'lkc_maybe_refresh_rewrite_rules', 99 );

/** Flush rewrite rules only on activation, deactivation, and a one-time upgrade. */
function lkc_activate() {
	lkc_register_post_types();
	lkc_register_taxonomies();
	if ( function_exists( 'lkc_engagement_install_tables' ) ) {
		lkc_engagement_install_tables();
	}
	flush_rewrite_rules();
	update_option( 'lkc_rewrite_version', '1.3.0', false );
}
register_activation_hook( __FILE__, 'lkc_activate' );

function lkc_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'lkc_deactivate' );
