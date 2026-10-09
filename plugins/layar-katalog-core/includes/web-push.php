<?php
/**
 * VAPID Web Push for newly published episodes.
 *
 * @package Layar_Katalog_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lkc_push_subscriptions_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'lkc_push_subscriptions';
}

function lkc_get_push_settings() {
	$saved = get_option( 'lkc_push_options', array() );
	$saved = is_array( $saved ) ? $saved : array();
	return array(
		'enabled'     => ! empty( $saved['enabled'] ),
		'subject'     => isset( $saved['subject'] ) && is_string( $saved['subject'] ) ? $saved['subject'] : 'mailto:' . sanitize_email( get_option( 'admin_email' ) ),
		'public_key'  => isset( $saved['public_key'] ) && is_string( $saved['public_key'] ) ? $saved['public_key'] : '',
		'private_key' => isset( $saved['private_key'] ) && is_string( $saved['private_key'] ) ? $saved['private_key'] : '',
	);
}

function lkc_push_is_configured() {
	$options = lkc_get_push_settings();
	return ! empty( $options['enabled'] )
		&& ! empty( $options['public_key'] )
		&& ! empty( $options['private_key'] )
		&& class_exists( '\\Minishlink\\WebPush\\WebPush' )
		&& class_exists( '\\GuzzleHttp\\Client' );
}

function lkc_register_push_settings_page() {
	add_options_page(
		__( 'Notifikasi Episode', 'layar-katalog-core' ),
		__( 'Notifikasi Episode', 'layar-katalog-core' ),
		'manage_options',
		'lkc-push',
		'lkc_render_push_settings_page'
	);
}
add_action( 'admin_menu', 'lkc_register_push_settings_page' );

function lkc_push_admin_notice_url( $notice ) {
	return add_query_arg( 'lkc_push_notice', sanitize_key( $notice ), admin_url( 'options-general.php?page=lkc-push' ) );
}

function lkc_handle_save_push_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Anda tidak memiliki izin untuk mengatur notifikasi.', 'layar-katalog-core' ) );
	}
	check_admin_referer( 'lkc_save_push_settings' );
	$old     = lkc_get_push_settings();
	$subject = isset( $_POST['subject'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['subject'] ) ) ) : $old['subject'];
	if ( 0 === stripos( $subject, 'mailto:' ) ) {
		$email   = sanitize_email( substr( $subject, 7 ) );
		$subject = $email ? 'mailto:' . $email : '';
	} else {
		$subject_url = esc_url_raw( $subject, array( 'https' ) );
		$subject_host = wp_parse_url( $subject_url, PHP_URL_HOST );
		$subject_scheme = wp_parse_url( $subject_url, PHP_URL_SCHEME );
		$subject = ( $subject_host && 'https' === $subject_scheme ) ? $subject_url : '';
	}
	if ( ! $subject ) {
		$subject = 'mailto:' . sanitize_email( get_option( 'admin_email' ) );
	}
	$enabled = isset( $_POST['enabled'] ) && '1' === wp_unslash( $_POST['enabled'] );
	if ( $enabled && ( empty( $old['public_key'] ) || empty( $old['private_key'] ) ) ) {
		$enabled = false;
	}
	$options = array(
		'enabled'     => $enabled,
		'subject'     => $subject,
		'public_key'  => $old['public_key'],
		'private_key' => $old['private_key'],
	);
	update_option( 'lkc_push_options', $options, false );
	wp_safe_redirect( lkc_push_admin_notice_url( $enabled ? 'saved' : 'saved_disabled' ) );
	exit;
}
add_action( 'admin_post_lkc_save_push_settings', 'lkc_handle_save_push_settings' );

function lkc_handle_generate_vapid_keys() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Anda tidak memiliki izin untuk membuat kunci VAPID.', 'layar-katalog-core' ) );
	}
	check_admin_referer( 'lkc_generate_vapid_keys' );
	if ( ! class_exists( '\\Minishlink\\WebPush\\VAPID' ) ) {
		wp_safe_redirect( lkc_push_admin_notice_url( 'library_missing' ) );
		exit;
	}
	try {
		$keys    = \Minishlink\WebPush\VAPID::createVapidKeys();
		$old     = lkc_get_push_settings();
		$options = array(
			'enabled'     => false,
			'subject'     => $old['subject'],
			'public_key'  => $keys['publicKey'],
			'private_key' => $keys['privateKey'],
		);
		update_option( 'lkc_push_options', $options, false );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . lkc_push_subscriptions_table_name() );
		wp_safe_redirect( lkc_push_admin_notice_url( 'keys_generated' ) );
	} catch ( Throwable $error ) {
		wp_safe_redirect( lkc_push_admin_notice_url( 'key_error' ) );
	}
	exit;
}
add_action( 'admin_post_lkc_generate_vapid_keys', 'lkc_handle_generate_vapid_keys' );

function lkc_handle_clear_push_subscriptions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Anda tidak memiliki izin untuk menghapus langganan.', 'layar-katalog-core' ) );
	}
	check_admin_referer( 'lkc_clear_push_subscriptions' );
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . lkc_push_subscriptions_table_name() );
	wp_safe_redirect( lkc_push_admin_notice_url( 'subscriptions_cleared' ) );
	exit;
}
add_action( 'admin_post_lkc_clear_push_subscriptions', 'lkc_handle_clear_push_subscriptions' );

function lkc_render_push_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Anda tidak memiliki izin untuk mengatur notifikasi.', 'layar-katalog-core' ) );
	}
	$options = lkc_get_push_settings();
	global $wpdb;
	$count = absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . lkc_push_subscriptions_table_name() ) );
	$notices = array(
		'saved'                 => __( 'Pengaturan disimpan dan notifikasi aktif.', 'layar-katalog-core' ),
		'saved_disabled'        => __( 'Pengaturan disimpan. Notifikasi belum aktif; buat kunci VAPID dan aktifkan fitur.', 'layar-katalog-core' ),
		'keys_generated'        => __( 'Kunci baru dibuat. Perangkat lama harus berlangganan kembali.', 'layar-katalog-core' ),
		'library_missing'       => __( 'Library Web Push belum tersedia. Pastikan paket plugin terpasang lengkap.', 'layar-katalog-core' ),
		'key_error'             => __( 'Kunci VAPID gagal dibuat. Periksa ekstensi OpenSSL dan log server.', 'layar-katalog-core' ),
		'subscriptions_cleared' => __( 'Semua langganan push dihapus.', 'layar-katalog-core' ),
	);
	$notice_key = isset( $_GET['lkc_push_notice'] ) ? sanitize_key( wp_unslash( $_GET['lkc_push_notice'] ) ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Notifikasi episode baru', 'layar-katalog-core' ); ?></h1>
		<?php if ( isset( $notices[ $notice_key ] ) ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notices[ $notice_key ] ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Push dikirim hanya kepada browser yang menekan tombol berlangganan. Setiap episode baru yang diterbitkan akan diberitahukan kepada semua pelanggan aktif.', 'layar-katalog-core' ); ?></p>
		<p><strong><?php esc_html_e( 'Persyaratan:', 'layar-katalog-core' ); ?></strong> <?php esc_html_e( 'Situs harus memakai HTTPS, hosting memerlukan PHP 8.2+ beserta OpenSSL/cURL/mbstring, dan WP-Cron harus berjalan. Kunci privat VAPID disimpan di database WordPress; batasi akses admin dan lindungi cadangannya.', 'layar-katalog-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lkc_save_push_settings">
			<?php wp_nonce_field( 'lkc_save_push_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Aktifkan push', 'layar-katalog-core' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $options['enabled'] ); ?>> <?php esc_html_e( 'Kirim pemberitahuan saat episode baru terbit', 'layar-katalog-core' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="lkc-vapid-subject"><?php esc_html_e( 'Subjek VAPID', 'layar-katalog-core' ); ?></label></th>
					<td><input class="regular-text" type="text" id="lkc-vapid-subject" name="subject" value="<?php echo esc_attr( $options['subject'] ); ?>"><p class="description"><?php esc_html_e( 'Alamat mailto: atau URL HTTPS untuk kontak pengelola situs.', 'layar-katalog-core' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kunci publik', 'layar-katalog-core' ); ?></th>
					<td><?php if ( $options['public_key'] ) : ?><code style="word-break:break-all"><?php echo esc_html( $options['public_key'] ); ?></code><?php else : ?><span><?php esc_html_e( 'Belum dibuat', 'layar-katalog-core' ); ?></span><?php endif; ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kunci privat', 'layar-katalog-core' ); ?></th>
					<td><?php echo $options['private_key'] ? esc_html__( 'Tersimpan (tidak ditampilkan).', 'layar-katalog-core' ) : esc_html__( 'Belum dibuat.', 'layar-katalog-core' ); ?></td>
				</tr>
			</table>
			<?php submit_button( __( 'Simpan pengaturan', 'layar-katalog-core' ) ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem">
			<input type="hidden" name="action" value="lkc_generate_vapid_keys">
			<?php wp_nonce_field( 'lkc_generate_vapid_keys' ); ?>
			<?php submit_button( $options['public_key'] ? __( 'Buat ulang kunci VAPID', 'layar-katalog-core' ) : __( 'Buat kunci VAPID', 'layar-katalog-core' ), 'secondary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( 'Membuat ulang kunci akan menghapus endpoint lama dan mengharuskan pelanggan berlangganan kembali.', 'layar-katalog-core' ); ?></p>
		</form>
		<hr>
		<h2><?php esc_html_e( 'Pelanggan push', 'layar-katalog-core' ); ?></h2>
		<p><?php echo esc_html( sprintf( __( '%s perangkat tersimpan.', 'layar-katalog-core' ), number_format_i18n( $count ) ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Hapus semua perangkat push tersimpan?', 'layar-katalog-core' ) ); ?>');">
			<input type="hidden" name="action" value="lkc_clear_push_subscriptions">
			<?php wp_nonce_field( 'lkc_clear_push_subscriptions' ); ?>
			<?php submit_button( __( 'Hapus semua langganan', 'layar-katalog-core' ), 'delete', 'submit', false ); ?>
		</form>
		<hr>
		<p><?php esc_html_e( 'Letakkan shortcode [lkc_push_toggle] pada halaman atau template untuk menampilkan tombol persetujuan berlangganan. Tombol tidak meminta izin browser sebelum diklik.', 'layar-katalog-core' ); ?></p>
	</div>
	<?php
}

/** Validate and limit push endpoints to known public browser push services (avoid SSRF). */
function lkc_validate_push_endpoint( $endpoint ) {
	if ( ! is_string( $endpoint ) || strlen( $endpoint ) > 2048 || ! function_exists( 'wp_http_validate_url' ) ) {
		return false;
	}
	$endpoint = trim( $endpoint );
	if ( ! wp_http_validate_url( $endpoint ) ) {
		return false;
	}
	$parts = wp_parse_url( $endpoint );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
		return false;
	}
	$host = strtolower( rtrim( $parts['host'], '.' ) );
	$allowed_domains = array(
		'fcm.googleapis.com',
		'push.services.mozilla.com',
		'apple.push.apple.com',
		'push.apple.com',
	);
	foreach ( $allowed_domains as $domain ) {
		if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
			return esc_url_raw( $endpoint, array( 'https' ) );
		}
	}
	return false;
}

function lkc_register_push_routes() {
	register_rest_route(
		'lkc/v1',
		'/push/key',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'lkc_rest_get_push_key',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'lkc/v1',
		'/push/subscribe',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'lkc_rest_save_push_subscription',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'lkc/v1',
		'/push/unsubscribe',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'lkc_rest_delete_push_subscription',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'lkc_register_push_routes' );

function lkc_rest_get_push_key() {
	$options = lkc_get_push_settings();
	if ( ! lkc_push_is_configured() ) {
		return new WP_Error( 'lkc_push_unavailable', __( 'Notifikasi belum aktif.', 'layar-katalog-core' ), array( 'status' => 503 ) );
	}
	return rest_ensure_response( array( 'enabled' => true, 'publicKey' => $options['public_key'] ) );
}

function lkc_decode_base64url_key( $value, $expected_length ) {
	if ( ! is_string( $value ) || strlen( $value ) > 256 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
		return false;
	}
	$base64 = strtr( $value, '-_', '+/' );
	$base64 .= str_repeat( '=', ( 4 - strlen( $base64 ) % 4 ) % 4 );
	$decoded = base64_decode( $base64, true );
	return ( false !== $decoded && strlen( $decoded ) === $expected_length ) ? $value : false;
}

function lkc_rest_save_push_subscription( $request ) {
	if ( ! lkc_push_is_configured() ) {
		return new WP_Error( 'lkc_push_unavailable', __( 'Notifikasi belum aktif.', 'layar-katalog-core' ), array( 'status' => 503 ) );
	}
	$data     = $request->get_json_params();
	$endpoint = isset( $data['endpoint'] ) ? lkc_validate_push_endpoint( $data['endpoint'] ) : false;
	$keys     = isset( $data['keys'] ) && is_array( $data['keys'] ) ? $data['keys'] : array();
	$p256dh   = isset( $keys['p256dh'] ) ? lkc_decode_base64url_key( $keys['p256dh'], 65 ) : false;
	$auth     = isset( $keys['auth'] ) ? lkc_decode_base64url_key( $keys['auth'], 16 ) : false;
	if ( ! $endpoint || ! $p256dh || ! $auth ) {
		return new WP_Error( 'lkc_push_invalid_subscription', __( 'Data langganan browser tidak valid.', 'layar-katalog-core' ), array( 'status' => 400 ) );
	}
	$encoding = isset( $data['contentEncoding'] ) && 'aesgcm' === $data['contentEncoding'] ? 'aesgcm' : 'aes128gcm';
	global $wpdb;
	$table = lkc_push_subscriptions_table_name();
	$hash  = hash( 'sha256', $endpoint );
	$saved = $wpdb->replace(
		$table,
		array(
			'subscription_hash' => $hash,
			'endpoint'          => $endpoint,
			'p256dh'             => $p256dh,
			'auth_token'         => $auth,
			'content_encoding'   => $encoding,
			'created_at'         => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( false === $saved ) {
		return new WP_Error( 'lkc_push_save_failed', __( 'Langganan belum tersimpan. Coba lagi.', 'layar-katalog-core' ), array( 'status' => 500 ) );
	}
	return rest_ensure_response( array( 'saved' => true ) );
}

function lkc_rest_delete_push_subscription( $request ) {
	$data     = $request->get_json_params();
	$endpoint = isset( $data['endpoint'] ) ? lkc_validate_push_endpoint( $data['endpoint'] ) : false;
	if ( ! $endpoint ) {
		return new WP_Error( 'lkc_push_invalid_endpoint', __( 'Endpoint browser tidak valid.', 'layar-katalog-core' ), array( 'status' => 400 ) );
	}
	global $wpdb;
	$wpdb->delete( lkc_push_subscriptions_table_name(), array( 'subscription_hash' => hash( 'sha256', $endpoint ) ), array( '%s' ) );
	return rest_ensure_response( array( 'deleted' => true ) );
}

function lkc_push_toggle_shortcode() {
	if ( ! lkc_push_is_configured() ) {
		return '<p class="lkc-push-note">' . esc_html__( 'Notifikasi episode belum dikonfigurasi oleh pengelola situs.', 'layar-katalog-core' ) . '</p>';
	}
	return '<div class="lkc-push-toggle" data-lkc-push-toggle><button type="button" class="lkc-push-toggle__button" data-lkc-push-button>' . esc_html__( 'Aktifkan notifikasi episode', 'layar-katalog-core' ) . '</button><p class="lkc-push-toggle__status" data-lkc-push-status aria-live="polite">' . esc_html__( 'Notifikasi hanya aktif setelah Anda menyetujuinya.', 'layar-katalog-core' ) . '</p><p class="lkc-push-toggle__privacy">' . esc_html__( 'Jika aktif, Anda menerima episode baru dari seluruh katalog. Endpoint browser disimpan untuk mengirim push; matikan kapan saja.', 'layar-katalog-core' ) . '</p></div>';
}
add_shortcode( 'lkc_push_toggle', 'lkc_push_toggle_shortcode' );

function lkc_enqueue_push_assets() {
	$needed = is_post_type_archive( 'lk_episode' );
	if ( ! $needed && is_page() ) {
		$page = get_post( get_queried_object_id() );
		$needed = $page && has_shortcode( $page->post_content, 'lkc_push_toggle' );
	}
	if ( ! $needed || ! lkc_push_is_configured() ) {
		return;
	}
	$script_path = LKC_PLUGIN_DIR . 'assets/push.js';
	$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : LKC_PLUGIN_VERSION;
	wp_enqueue_script( 'lkc-push', LKC_PLUGIN_URL . 'assets/push.js', array(), $version, true );
	wp_localize_script(
		'lkc-push',
		'LKCPush',
		array(
			'keyUrl'          => esc_url_raw( rest_url( 'lkc/v1/push/key' ) ),
			'subscribeUrl'    => esc_url_raw( rest_url( 'lkc/v1/push/subscribe' ) ),
			'unsubscribeUrl'  => esc_url_raw( rest_url( 'lkc/v1/push/unsubscribe' ) ),
			'serviceWorkerUrl'=> esc_url_raw( LKC_PLUGIN_URL . 'service-worker.js?ver=' . rawurlencode( LKC_PLUGIN_VERSION ) ),
			'strings'         => array(
				'enable'          => __( 'Aktifkan notifikasi episode', 'layar-katalog-core' ),
				'disable'         => __( 'Matikan notifikasi episode', 'layar-katalog-core' ),
				'active'          => __( 'Notifikasi episode aktif di browser ini.', 'layar-katalog-core' ),
				'inactive'        => __( 'Notifikasi hanya aktif setelah Anda menyetujuinya.', 'layar-katalog-core' ),
				'permission'      => __( 'Izin notifikasi ditolak. Ubah izin situs ini di pengaturan browser.', 'layar-katalog-core' ),
				'unsupported'     => __( 'Browser ini tidak mendukung Web Push atau situs belum memakai HTTPS.', 'layar-katalog-core' ),
				'error'           => __( 'Notifikasi belum berhasil diatur. Coba lagi nanti.', 'layar-katalog-core' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'lkc_enqueue_push_assets', 21 );

function lkc_schedule_new_episode_push( $new_status, $old_status, $post ) {
	if ( ! $post instanceof WP_Post || 'lk_episode' !== $post->post_type || 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( ! lkc_push_is_configured() || get_post_meta( $post->ID, '_lkc_push_sent_at', true ) ) {
		return;
	}
	$hook = 'lkc_process_episode_push_batch';
	$args = array( (int) $post->ID, '' );
	if ( wp_next_scheduled( $hook, $args ) ) {
		return;
	}
	global $wpdb;
	$has_subscribers = $wpdb->get_var( 'SELECT subscription_hash FROM ' . lkc_push_subscriptions_table_name() . ' LIMIT 1' );
	if ( ! $has_subscribers ) {
		return;
	}
	update_post_meta( $post->ID, '_lkc_push_queued_at', current_time( 'mysql', true ) );
	wp_schedule_single_event( time() + 15, $hook, $args );
}
add_action( 'transition_post_status', 'lkc_schedule_new_episode_push', 10, 3 );
add_action( 'lkc_process_episode_push_batch', 'lkc_process_episode_push_batch', 10, 2 );

/** Send one small batch during cron to avoid delaying an editor's publish request. */
function lkc_process_episode_push_batch( $episode_id, $after_hash = '' ) {
	$episode_id = absint( $episode_id );
	if ( ! $episode_id || 'publish' !== get_post_status( $episode_id ) || get_post_meta( $episode_id, '_lkc_push_sent_at', true ) || ! lkc_push_is_configured() ) {
		return;
	}
	global $wpdb;
	$table      = lkc_push_subscriptions_table_name();
	$batch_size = 10;
	$rows       = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT subscription_hash, endpoint, p256dh, auth_token, content_encoding FROM {$table} WHERE subscription_hash > %s ORDER BY subscription_hash ASC LIMIT %d",
			(string) $after_hash,
			$batch_size
		)
	);
	if ( empty( $rows ) ) {
		update_post_meta( $episode_id, '_lkc_push_sent_at', current_time( 'mysql', true ) );
		delete_post_meta( $episode_id, '_lkc_push_queued_at' );
		return;
	}

	$settings = lkc_get_push_settings();
	try {
		$http_client = new \GuzzleHttp\Client( array( 'timeout' => 5, 'connect_timeout' => 3, 'allow_redirects' => false ) );
		$web_push    = new \Minishlink\WebPush\WebPush(
			array(
				'VAPID' => array(
					'subject'    => $settings['subject'],
					'publicKey'  => $settings['public_key'],
					'privateKey' => $settings['private_key'],
				),
			),
			array( 'TTL' => 86400, 'urgency' => 'normal', 'batchSize' => 1 ),
			$http_client
		);
	} catch ( Throwable $error ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Layar Katalog push initialization failed: ' . sanitize_text_field( $error->getMessage() ) );
		}
		return;
	}

	$parent_id = absint( get_post_meta( $episode_id, '_lk_parent_title', true ) );
	$title     = get_the_title( $episode_id );
	$series    = $parent_id ? get_the_title( $parent_id ) : '';
	$body      = $series ? $title . ' — ' . $series : $title;
	$payload   = wp_json_encode(
		array(
			'title' => __( 'Episode baru', 'layar-katalog-core' ),
			'body'  => wp_trim_words( $body, 14, '…' ),
			'url'   => get_permalink( $episode_id ),
			'icon'  => get_site_icon_url( 192 ),
			'tag'   => 'lkc-episode-' . $episode_id,
		)
	);

	$last_hash = '';
	foreach ( $rows as $row ) {
		$last_hash = $row->subscription_hash;
		try {
			$subscription = \Minishlink\WebPush\Subscription::create(
				array(
					'endpoint'        => $row->endpoint,
					'keys'            => array( 'p256dh' => $row->p256dh, 'auth' => $row->auth_token ),
					'contentEncoding' => $row->content_encoding,
				)
			);
			$report = $web_push->sendOneNotification( $subscription, $payload, array( 'TTL' => 86400, 'urgency' => 'normal' ) );
			if ( $report->isSubscriptionExpired() ) {
				$wpdb->delete( $table, array( 'subscription_hash' => $row->subscription_hash ), array( '%s' ) );
			}
		} catch ( Throwable $error ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Layar Katalog push send failed: ' . sanitize_text_field( $error->getMessage() ) );
			}
		}
	}

	if ( count( $rows ) === $batch_size ) {
		wp_schedule_single_event( time() + 5, 'lkc_process_episode_push_batch', array( $episode_id, $last_hash ) );
	} else {
		update_post_meta( $episode_id, '_lkc_push_sent_at', current_time( 'mysql', true ) );
		delete_post_meta( $episode_id, '_lkc_push_queued_at' );
	}
}
