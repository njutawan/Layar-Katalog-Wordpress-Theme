<?php
/**
 * Local watchlist/history and account-based user ratings.
 *
 * @package Layar_Katalog_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return the custom table used for one-rating-per-user storage. */
function lkc_user_ratings_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'lkc_user_ratings';
}

/** Create/update plugin-owned tables after upgrade. */
function lkc_engagement_install_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();
	$ratings_table   = lkc_user_ratings_table_name();
	$ratings_sql     = "CREATE TABLE {$ratings_table} (
		user_id bigint(20) unsigned NOT NULL,
		title_id bigint(20) unsigned NOT NULL,
		rating tinyint(3) unsigned NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (user_id,title_id),
		KEY title_id (title_id)
	) {$charset_collate};";
	dbDelta( $ratings_sql );

	if ( function_exists( 'lkc_push_subscriptions_table_name' ) ) {
		$subscriptions_table = lkc_push_subscriptions_table_name();
		$subscriptions_sql   = "CREATE TABLE {$subscriptions_table} (
			subscription_hash char(64) NOT NULL,
			endpoint text NOT NULL,
			p256dh varchar(128) NOT NULL,
			auth_token varchar(128) NOT NULL,
			content_encoding varchar(20) NOT NULL DEFAULT 'aes128gcm',
			created_at datetime NOT NULL,
			PRIMARY KEY  (subscription_hash),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $subscriptions_sql );
	}

	$ratings_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ratings_table ) ) );
	$push_exists    = function_exists( 'lkc_push_subscriptions_table_name' )
		? $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( lkc_push_subscriptions_table_name() ) ) )
		: false;
	if ( $ratings_table === $ratings_exists && function_exists( 'lkc_push_subscriptions_table_name' ) && lkc_push_subscriptions_table_name() === $push_exists ) {
		update_option( 'lkc_engagement_db_version', '1.0', false );
	}
}

function lkc_engagement_maybe_install_tables() {
	if ( '1.0' !== (string) get_option( 'lkc_engagement_db_version', '' ) ) {
		lkc_engagement_install_tables();
	}
}
add_action( 'plugins_loaded', 'lkc_engagement_maybe_install_tables', 5 );

/** Remove a member's stored ratings when their WordPress account is deleted. */
function lkc_delete_user_ratings( $user_id ) {
	global $wpdb;
	$user_id  = absint( $user_id );
	$table    = lkc_user_ratings_table_name();
	$title_ids = $wpdb->get_col( $wpdb->prepare( "SELECT title_id FROM {$table} WHERE user_id = %d", $user_id ) );
	foreach ( (array) $title_ids as $title_id ) {
		delete_transient( 'lkc_user_rating_' . absint( $title_id ) );
	}
	$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
}
add_action( 'delete_user', 'lkc_delete_user_ratings' );

function lkc_delete_title_ratings( $post_id ) {
	if ( 'lk_title' !== get_post_type( $post_id ) ) {
		return;
	}
	global $wpdb;
	$wpdb->delete( lkc_user_ratings_table_name(), array( 'title_id' => absint( $post_id ) ), array( '%d' ) );
	delete_transient( 'lkc_user_rating_' . absint( $post_id ) );
}
add_action( 'before_delete_post', 'lkc_delete_title_ratings' );

/** Add a clear privacy-policy summary for account ratings and browser-local data. */
function lkc_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = '<p><strong>' . esc_html__( 'Rating pengguna:', 'layar-katalog-core' ) . '</strong> ' . esc_html__( 'Rating 1–10 disimpan bersama ID akun WordPress dan waktu perubahan untuk mencegah lebih dari satu rating aktif per akun dan judul. Nilai agregat ditampilkan publik. Rating akun dihapus saat akun atau judul dihapus.', 'layar-katalog-core' ) . '</p>';
	$content .= '<p><strong>' . esc_html__( 'Notifikasi Web Push:', 'layar-katalog-core' ) . '</strong> ' . esc_html__( 'Jika Anda memilih berlangganan, endpoint browser dan kunci enkripsi push disimpan di situs untuk mengirim notifikasi episode baru. Anda dapat berhenti berlangganan lewat tombol push atau menghapus izin di browser. Notifikasi episode baru dikirim ke semua pelanggan aktif.', 'layar-katalog-core' ) . '</p>';
	$content .= '<p><strong>' . esc_html__( 'Watchlist dan riwayat:', 'layar-katalog-core' ) . '</strong> ' . esc_html__( 'Daftar tersimpan di localStorage browser/perangkat Anda, tidak dikirim ke server oleh fitur ini, dan dapat dihapus dengan membersihkan storage situs di browser.', 'layar-katalog-core' ) . '</p>';
	wp_add_privacy_policy_content( 'Layar Katalog Core', wp_kses_post( $content ) );
}
add_action( 'admin_init', 'lkc_add_privacy_policy_content' );

/** Get the public aggregate and the signed-in user's rating, if any. */
function lkc_get_user_rating_summary( $title_id ) {
	$title_id = absint( $title_id );
	$cache_key = 'lkc_user_rating_' . $title_id;
	$summary   = get_transient( $cache_key );
	if ( false === $summary || ! is_array( $summary ) ) {
		global $wpdb;
		$table = lkc_user_ratings_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS rating_count, AVG(rating) AS rating_average FROM {$table} WHERE title_id = %d",
				$title_id
			)
		);
		$summary = array(
			'count'   => $row ? absint( $row->rating_count ) : 0,
			'average' => ( $row && $row->rating_average ) ? round( (float) $row->rating_average, 1 ) : 0,
		);
		set_transient( $cache_key, $summary, 5 * MINUTE_IN_SECONDS );
	}

	$summary['my_rating'] = 0;
	$summary['can_rate']  = is_user_logged_in() && current_user_can( 'read' );
	if ( is_user_logged_in() ) {
		global $wpdb;
		$summary['my_rating'] = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT rating FROM " . lkc_user_ratings_table_name() . ' WHERE title_id = %d AND user_id = %d',
					$title_id,
					get_current_user_id()
				)
			)
		);
	}
	return $summary;
}

function lkc_register_user_rating_routes() {
	register_rest_route(
		'lkc/v1',
		'/ratings/(?P<title_id>\\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'lkc_rest_get_user_rating',
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'lkc_rest_save_user_rating',
				'permission_callback' => 'lkc_rest_can_rate_title',
			),
		)
	);
}
add_action( 'rest_api_init', 'lkc_register_user_rating_routes' );

function lkc_rest_can_rate_title() {
	if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
		return new WP_Error( 'lkc_rating_login_required', __( 'Masuk ke akun untuk memberi rating.', 'layar-katalog-core' ), array( 'status' => 401 ) );
	}
	return true;
}

function lkc_rest_rating_title( $title_id ) {
	$title = get_post( absint( $title_id ) );
	if ( ! $title || 'lk_title' !== $title->post_type || 'publish' !== $title->post_status ) {
		return new WP_Error( 'lkc_rating_invalid_title', __( 'Item katalog tidak ditemukan.', 'layar-katalog-core' ), array( 'status' => 404 ) );
	}
	return $title;
}

function lkc_user_rating_rest_response( $data ) {
	$response = rest_ensure_response( $data );
	if ( $response instanceof WP_REST_Response ) {
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
	}
	return $response;
}

function lkc_rest_get_user_rating( $request ) {
	$title = lkc_rest_rating_title( $request['title_id'] );
	if ( is_wp_error( $title ) ) {
		return $title;
	}
	return lkc_user_rating_rest_response( lkc_get_user_rating_summary( $title->ID ) );
}

function lkc_rest_save_user_rating( $request ) {
	$title = lkc_rest_rating_title( $request['title_id'] );
	if ( is_wp_error( $title ) ) {
		return $title;
	}
	$raw_rating = $request->get_param( 'rating' );
	if ( ! is_scalar( $raw_rating ) || ! preg_match( '/^(?:[1-9]|10)$/', (string) $raw_rating ) ) {
		return new WP_Error( 'lkc_rating_invalid_value', __( 'Pilih rating bilangan bulat dari 1 sampai 10.', 'layar-katalog-core' ), array( 'status' => 400 ) );
	}

	global $wpdb;
	$saved = $wpdb->replace(
		lkc_user_ratings_table_name(),
		array(
			'user_id'    => get_current_user_id(),
			'title_id'   => $title->ID,
			'rating'     => (int) $raw_rating,
			'updated_at' => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%d', '%s' )
	);
	if ( false === $saved ) {
		return new WP_Error( 'lkc_rating_save_failed', __( 'Rating belum berhasil disimpan. Coba lagi.', 'layar-katalog-core' ), array( 'status' => 500 ) );
	}
	delete_transient( 'lkc_user_rating_' . $title->ID );
	return lkc_user_rating_rest_response( lkc_get_user_rating_summary( $title->ID ) );
}

/** Show the user-generated average without mixing it with the editorial score. */
function lkc_user_rating_shortcode( $atts = array() ) {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_user_rating' );
	$title_id = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();
	if ( ! $title_id || 'lk_title' !== get_post_type( $title_id ) ) {
		return '';
	}

	$summary   = lkc_get_user_rating_summary( $title_id );
	$rating_url = rest_url( 'lkc/v1/ratings/' . $title_id );
	$average   = $summary['count'] ? number_format_i18n( $summary['average'], 1 ) : '—';
	$count     = number_format_i18n( $summary['count'] );
	$output    = '<section class="lk-user-rating" data-lkc-user-rating data-rating-url="' . esc_url( $rating_url ) . '">';
	$output   .= '<p class="lk-user-rating__eyebrow">' . esc_html__( 'RATING PENGGUNA', 'layar-katalog-core' ) . '</p>';
	$output   .= '<p class="lk-user-rating__summary"><strong><span data-lkc-rating-average>' . esc_html( $average ) . '</span><small> / 10</small></strong> <span data-lkc-rating-count>' . esc_html( $count ) . '</span> ' . esc_html__( 'suara', 'layar-katalog-core' ) . '</p>';

	$input_id = wp_unique_id( 'lk-user-rating-' . $title_id . '-' );
	$output  .= '<p class="lk-user-rating__load" data-lkc-rating-load aria-live="polite">' . esc_html__( 'Memuat rating…', 'layar-katalog-core' ) . '</p>';
	$output  .= '<form class="lk-user-rating__form" data-lkc-rating-form hidden><label for="' . esc_attr( $input_id ) . '">' . esc_html__( 'Nilai Anda (1–10)', 'layar-katalog-core' ) . '</label>';
	$output  .= '<input id="' . esc_attr( $input_id ) . '" type="range" min="1" max="10" step="1" value="5" data-lkc-rating-input><output data-lkc-rating-output>5</output>';
	$output  .= '<button type="submit">' . esc_html__( 'Simpan rating', 'layar-katalog-core' ) . '</button><p class="lk-user-rating__status" data-lkc-rating-status aria-live="polite"></p></form>';
	$output  .= '<p class="lk-user-rating__login" data-lkc-rating-login hidden><a href="' . esc_url( wp_login_url( get_permalink( $title_id ) ) ) . '">' . esc_html__( 'Masuk untuk memberi rating', 'layar-katalog-core' ) . '</a></p>';
	$output  .= '<p class="lk-user-rating__note">' . esc_html__( 'Satu rating per akun. Anda dapat mengubahnya kapan saja.', 'layar-katalog-core' ) . '</p>';
	$output  .= '<noscript><p class="lk-user-rating__login">' . esc_html__( 'Aktifkan JavaScript dan masuk ke akun untuk memberi rating.', 'layar-katalog-core' ) . '</p></noscript>';

	$output .= '</section>';
	return $output;
}
add_shortcode( 'lk_user_rating', 'lkc_user_rating_shortcode' );

/** Button for a browser-local, non-synchronized watchlist. */
function lkc_watchlist_button_shortcode( $atts = array() ) {
	$atts     = shortcode_atts( array( 'id' => 0 ), $atts, 'lk_watchlist_button' );
	$title_id = absint( $atts['id'] ) ? absint( $atts['id'] ) : get_the_ID();
	$title    = get_post( $title_id );
	if ( ! $title || 'lk_title' !== $title->post_type || 'publish' !== $title->post_status ) {
		return '';
	}
	$image = get_the_post_thumbnail_url( $title_id, 'medium' );
	return '<button type="button" class="lkc-watchlist-toggle" data-lkc-watchlist-toggle data-id="' . esc_attr( $title_id ) . '" data-title="' . esc_attr( get_the_title( $title_id ) ) . '" data-url="' . esc_url( get_permalink( $title_id ) ) . '" data-image="' . esc_url( $image ? $image : '' ) . '" aria-pressed="false">' . esc_html__( 'Simpan ke watchlist', 'layar-katalog-core' ) . '</button><span class="lkc-watchlist-status screen-reader-text" data-lkc-watchlist-status aria-live="polite"></span>';
}
add_shortcode( 'lk_watchlist_button', 'lkc_watchlist_button_shortcode' );

/** Render a client-side watchlist; no titles or user activity are sent to WordPress. */
function lkc_watchlist_shortcode() {
	return '<section class="lkc-local-list lkc-watchlist" data-lkc-watchlist-list><h2>' . esc_html__( 'Watchlist saya', 'layar-katalog-core' ) . '</h2><p class="lkc-local-list__privacy">' . esc_html__( 'Daftar ini tersimpan hanya di browser dan perangkat ini.', 'layar-katalog-core' ) . '</p><p class="lkc-local-list__empty" data-lkc-list-empty hidden>' . esc_html__( 'Belum ada judul tersimpan.', 'layar-katalog-core' ) . '</p><ul class="lkc-local-list__items" data-lkc-list-items></ul><button type="button" class="lkc-local-list__clear" data-lkc-clear-list hidden>' . esc_html__( 'Hapus semua dari watchlist', 'layar-katalog-core' ) . '</button><p class="screen-reader-text" data-lkc-list-status aria-live="polite"></p></section>';
}
add_shortcode( 'lk_watchlist', 'lkc_watchlist_shortcode' );

/** Render local episode viewing history. */
function lkc_viewing_history_shortcode() {
	return '<section class="lkc-local-list lkc-history" data-lkc-history-list><h2>' . esc_html__( 'Riwayat tontonan', 'layar-katalog-core' ) . '</h2><p class="lkc-local-list__privacy">' . esc_html__( 'Riwayat ini tersimpan hanya di browser dan perangkat ini. Riwayat dibatasi ke 50 episode terakhir.', 'layar-katalog-core' ) . '</p><p class="lkc-local-list__empty" data-lkc-list-empty hidden>' . esc_html__( 'Riwayat masih kosong. Episode yang Anda buka akan muncul di sini.', 'layar-katalog-core' ) . '</p><ul class="lkc-local-list__items" data-lkc-list-items></ul><button type="button" class="lkc-local-list__clear" data-lkc-clear-list hidden>' . esc_html__( 'Hapus riwayat', 'layar-katalog-core' ) . '</button><p class="screen-reader-text" data-lkc-list-status aria-live="polite"></p></section>';
}
add_shortcode( 'lk_viewing_history', 'lkc_viewing_history_shortcode' );

/** Marker included only in a single episode template to record a local history entry. */
function lkc_episode_history_marker_shortcode() {
	$episode_id = get_the_ID();
	$episode    = get_post( $episode_id );
	if ( ! $episode || 'lk_episode' !== $episode->post_type || ! is_singular( 'lk_episode' ) ) {
		return '';
	}
	$parent_id    = absint( get_post_meta( $episode_id, '_lk_parent_title', true ) );
	$parent_title = $parent_id ? get_the_title( $parent_id ) : '';
	$parent_url   = $parent_id ? get_permalink( $parent_id ) : '';
	$image        = get_the_post_thumbnail_url( $episode_id, 'medium' );
	if ( ! $image && $parent_id ) {
		$image = get_the_post_thumbnail_url( $parent_id, 'medium' );
	}
	return '<span hidden data-lkc-history-entry data-id="' . esc_attr( $episode_id ) . '" data-title="' . esc_attr( get_the_title( $episode_id ) ) . '" data-url="' . esc_url( get_permalink( $episode_id ) ) . '" data-parent-title="' . esc_attr( $parent_title ) . '" data-parent-url="' . esc_url( $parent_url ) . '" data-image="' . esc_url( $image ? $image : '' ) . '"></span>';
}
add_shortcode( 'lk_episode_history_marker', 'lkc_episode_history_marker_shortcode' );

/** Load the small browser-storage script only where one of its controls is used. */
function lkc_enqueue_engagement_assets() {
	$needed = is_singular( array( 'lk_title', 'lk_episode' ) );
	if ( ! $needed && is_page() ) {
		$page = get_post( get_queried_object_id() );
		if ( $page ) {
			foreach ( array( 'lk_watchlist', 'lk_viewing_history', 'lk_watchlist_button', 'lk_user_rating', 'lk_share_buttons' ) as $shortcode ) {
				if ( has_shortcode( $page->post_content, $shortcode ) ) {
					$needed = true;
					break;
				}
			}
		}
	}
	if ( ! $needed ) {
		return;
	}
	$script_path = LKC_PLUGIN_DIR . 'assets/engagement.js';
	$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : LKC_PLUGIN_VERSION;
	wp_enqueue_script( 'lkc-engagement', LKC_PLUGIN_URL . 'assets/engagement.js', array(), $version, true );
	wp_localize_script(
		'lkc-engagement',
		'LKCEngagement',
		array(
			'restUrl' => esc_url_raw( rest_url( 'lkc/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		'strings' => array(
			'added'          => __( 'Ditambahkan ke watchlist.', 'layar-katalog-core' ),
			'removed'        => __( 'Dihapus dari watchlist.', 'layar-katalog-core' ),
			'emptyWatchlist' => __( 'Belum ada judul tersimpan.', 'layar-katalog-core' ),
			'emptyHistory'   => __( 'Riwayat masih kosong.', 'layar-katalog-core' ),
			'clearConfirm'   => __( 'Hapus semua item dari daftar ini?', 'layar-katalog-core' ),
			'cleared'        => __( 'Daftar berhasil dikosongkan.', 'layar-katalog-core' ),
			'itemRemoved'    => __( 'Item dihapus.', 'layar-katalog-core' ),
			'ratingSaved'    => __( 'Rating tersimpan.', 'layar-katalog-core' ),
			'ratingFailed'   => __( 'Rating belum berhasil disimpan. Coba lagi.', 'layar-katalog-core' ),
			'ratingLoadFailed' => __( 'Status rating tidak dapat dimuat. Coba muat ulang halaman.', 'layar-katalog-core' ),
			'storageError'   => __( 'Penyimpanan browser tidak tersedia.', 'layar-katalog-core' ),
		),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'lkc_enqueue_engagement_assets', 20 );
