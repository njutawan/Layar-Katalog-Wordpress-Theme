<?php
/**
 * Security audit trail and Content-Security-Policy report collector.
 *
 * @package Layar_Katalog_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Name of the table holding administrative audit entries. */
function lkc_audit_log_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'lkc_audit_log';
}

/** Name of the table holding aggregated CSP violation reports. */
function lkc_csp_report_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'lkc_csp_reports';
}

/**
 * Whether one of the plugin's tables exists, checked once per request.
 *
 * Auditing and report collection must never break a request, so both bail out
 * quietly when the tables are missing (for example when the database user lacks
 * CREATE TABLE rights).
 *
 * @param string $table Fully prefixed table name.
 * @return bool
 */
function lkc_security_table_exists( $table ) {
	static $known = array();

	if ( isset( $known[ $table ] ) ) {
		return $known[ $table ];
	}

	global $wpdb;
	$found           = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	$known[ $table ] = ( $table === $found );

	return $known[ $table ];
}

/** Create or update the plugin-owned security tables. */
function lkc_install_security_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();

	$audit_table = lkc_audit_log_table_name();
	dbDelta(
		"CREATE TABLE {$audit_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
		actor_login varchar(191) NOT NULL DEFAULT '',
		action varchar(100) NOT NULL,
		object_type varchar(40) NOT NULL DEFAULT '',
		object_id bigint(20) unsigned NOT NULL DEFAULT 0,
		detail varchar(255) NOT NULL DEFAULT '',
		ip_hash char(64) NOT NULL DEFAULT '',
		ua_hash char(64) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY action (action),
		KEY actor_id (actor_id),
		KEY created_at (created_at)
	) {$charset_collate};"
	);

	$report_table = lkc_csp_report_table_name();
	dbDelta(
		"CREATE TABLE {$report_table} (
		report_hash char(32) NOT NULL,
		directive varchar(60) NOT NULL DEFAULT '',
		blocked_host varchar(191) NOT NULL DEFAULT '',
		blocked_uri varchar(500) NOT NULL DEFAULT '',
		document_path varchar(255) NOT NULL DEFAULT '',
		violations bigint(20) unsigned NOT NULL DEFAULT 0,
		first_seen datetime NOT NULL,
		last_seen datetime NOT NULL,
		PRIMARY KEY  (report_hash),
		KEY directive (directive),
		KEY violations (violations),
		KEY last_seen (last_seen)
	) {$charset_collate};"
	);

	$audit_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $audit_table ) ) );
	$report_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $report_table ) ) );
	if ( $audit_table === $audit_exists && $report_table === $report_exists ) {
		update_option( 'lkc_security_db_version', '1.0', false );
	}
}

/** Install the security tables once, then only on upgrade. */
function lkc_maybe_install_security_tables() {
	if ( '1.0' !== (string) get_option( 'lkc_security_db_version', '' ) ) {
		lkc_install_security_tables();
	}
}
add_action( 'plugins_loaded', 'lkc_maybe_install_security_tables', 6 );

/**
 * Hash a value for storage so raw addresses and agents are never kept.
 *
 * @param string $value Raw address or user agent.
 * @return string HMAC-SHA256 hex digest, or an empty string for empty input.
 */
function lkc_hash_for_audit( $value ) {
	$value = trim( (string) $value );
	return '' === $value ? '' : hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
}

/**
 * Actions recorded by default.
 *
 * Volume-heavy events stay out of the log unless a site opts in. Add
 * 'user_rating_saved' through the `lkc_audit_log_actions` filter to watch for
 * rating manipulation, and 'push_subscription_saved' to watch subscription
 * growth.
 *
 * @return string[]
 */
function lkc_default_audit_actions() {
	return array(
		'push_settings_saved',
		'vapid_keys_rotated',
		'push_subscriptions_cleared',
		'push_subscriptions_pruned',
		'push_subscription_rejected',
		'ad_code_saved',
		'audit_log_cleared',
		'csp_reports_cleared',
	);
}

/**
 * Whether an action name is on the audit allowlist.
 *
 * @param string $action Machine-readable event name.
 * @return bool
 */
function lkc_is_audited_action( $action ) {
	$action = sanitize_key( (string) $action );
	if ( '' === $action ) {
		return false;
	}

	/**
	 * Filter which actions are written to the security audit log.
	 *
	 * High-volume events such as `rating_saved` and `push_subscription_saved`
	 * are left out by default so the table stays readable; add them here if a
	 * site needs a complete trail.
	 *
	 * @param string[] $actions Enabled action names.
	 */
	$enabled = (array) apply_filters( 'lkc_audit_log_actions', lkc_default_audit_actions() );

	return in_array( $action, $enabled, true );
}

/**
 * Record a security-relevant event.
 *
 * Failures are silent: an audit log must never be the reason a request breaks.
 *
 * @param string $action Machine-readable event name.
 * @param array  $args   Optional. `object_type`, `object_id`, `detail`.
 * @return bool Whether the entry was written.
 */
function lkc_write_audit_log( $action, $args = array() ) {
	global $wpdb;

	$action = sanitize_key( (string) $action );
	if ( '' === $action || ! lkc_is_audited_action( $action ) ) {
		return false;
	}

	$table = lkc_audit_log_table_name();
	if ( ! lkc_security_table_exists( $table ) ) {
		return false;
	}

	$args     = wp_parse_args(
		$args,
		array(
			'object_type' => '',
			'object_id'   => 0,
			'detail'      => '',
		)
	);
	$actor_id = get_current_user_id();
	$user     = $actor_id ? get_userdata( $actor_id ) : false;

	$inserted = $wpdb->insert(
		$table,
		array(
			'actor_id'    => absint( $actor_id ),
			'actor_login' => $user ? wp_html_excerpt( (string) $user->user_login, 191, '' ) : '',
			'action'      => $action,
			'object_type' => sanitize_key( $args['object_type'] ),
			'object_id'   => absint( $args['object_id'] ),
			'detail'      => wp_html_excerpt( sanitize_text_field( (string) $args['detail'] ), 255, '' ),
			'ip_hash'     => lkc_hash_for_audit( lkc_client_ip() ),
			'ua_hash'     => lkc_hash_for_audit( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ),
			'created_at'  => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
	);

	return false !== $inserted;
}

/** Register the violation-report collector used by the report-only policy. */
function lkc_register_csp_report_route() {
	register_rest_route(
		'lkc/v1',
		'/csp-report',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'lkc_rest_receive_csp_report',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'lkc_register_csp_report_route' );

/**
 * Aggregate a browser Content-Security-Policy violation report.
 *
 * Reports are folded into one row per (directive, blocked source, document path)
 * so a single misconfigured page cannot fill the table. Nothing here is trusted:
 * anyone can post a report, so every value is sanitised and truncated, and only
 * the path of the reporting document is kept.
 *
 * @param WP_REST_Request $request Incoming report.
 * @return WP_REST_Response
 */
function lkc_rest_receive_csp_report( $request ) {
	global $wpdb;

	$invalid = new WP_Error( 'lkc_csp_invalid_report', __( 'Laporan tidak valid.', 'layar-katalog-core' ), array( 'status' => 400 ) );

	$body = (string) $request->get_body();
	if ( '' === $body || strlen( $body ) > 2 * KB_IN_BYTES ) {
		return $invalid;
	}

	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		return $invalid;
	}

	/* CSP level 2 wraps the fields in "csp-report"; level 3 sends them at the top level. */
	$report = ( isset( $decoded['csp-report'] ) && is_array( $decoded['csp-report'] ) ) ? $decoded['csp-report'] : $decoded;
	if ( empty( $report ) ) {
		return $invalid;
	}

	$raw_directive = isset( $report['violated-directive'] ) ? (string) $report['violated-directive'] : '';
	if ( '' === $raw_directive && isset( $report['effectiveDirective'] ) ) {
		$raw_directive = (string) $report['effectiveDirective'];
	}
	/* Older browsers send the whole directive value; only the name is useful. */
	$directive = sanitize_key( (string) strtok( trim( $raw_directive ), " \t" ) );
	$directive = wp_html_excerpt( $directive, 60, '' );
	if ( '' === $directive ) {
		return $invalid;
	}

	$blocked_raw = isset( $report['blocked-uri'] ) ? (string) $report['blocked-uri'] : '';
	if ( '' === $blocked_raw && isset( $report['blockedURI'] ) ) {
		$blocked_raw = (string) $report['blockedURI'];
	}
	$blocked_raw = wp_html_excerpt( trim( $blocked_raw ), 500, '' );

	/* "inline", "eval", and "data" are keywords rather than URLs; keep them as-is. */
	$blocked_host = '';
	$blocked_path = '';
	if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $blocked_raw ) ) {
		$blocked_host = wp_html_excerpt( strtolower( (string) wp_parse_url( $blocked_raw, PHP_URL_HOST ) ), 191, '' );
		$blocked_path = wp_html_excerpt( (string) wp_parse_url( $blocked_raw, PHP_URL_PATH ), 255, '' );
	} else {
		$blocked_host = wp_html_excerpt( strtolower( $blocked_raw ), 191, '' );
	}

	$document_raw  = '';
	$document_path = '';
	if ( isset( $report['document-uri'] ) ) {
		$document_raw = (string) $report['document-uri'];
	} elseif ( isset( $report['documentURI'] ) ) {
		$document_raw = (string) $report['documentURI'];
	}
	if ( $document_raw ) {
		/* Only the path: a query string can carry nonces or preview tokens. */
		$document_path = wp_html_excerpt( (string) wp_parse_url( $document_raw, PHP_URL_PATH ), 255, '' );
	}

	$table = lkc_csp_report_table_name();
	if ( ! lkc_security_table_exists( $table ) ) {
		return new WP_REST_Response( null, 204 );
	}

	$hash  = substr( hash( 'sha256', $directive . '|' . $blocked_host . '|' . $blocked_path . '|' . $document_path ), 0, 32 );
	$now   = current_time( 'mysql', true );
	$known = $wpdb->get_var( $wpdb->prepare( "SELECT report_hash FROM {$table} WHERE report_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix and is not user input.

	if ( $known ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET violations = violations + 1, last_seen = %s WHERE report_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$hash
			)
		);
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Filter how many distinct violation fingerprints are kept.
	 *
	 * Once the ceiling is reached, known violations keep counting but no new rows
	 * are created, so an attacker cannot fill the table with unique URLs.
	 *
	 * @param int $max_rows Maximum number of stored fingerprints.
	 */
	$max_rows = absint( apply_filters( 'lkc_csp_report_max_rows', 500 ) );
	if ( $max_rows ) {
		$rows = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix.
		if ( $rows >= $max_rows ) {
			return new WP_REST_Response( null, 204 );
		}
	}

	$wpdb->insert(
		$table,
		array(
			'report_hash'   => $hash,
			'directive'     => $directive,
			'blocked_host'  => $blocked_host,
			'blocked_uri'   => $blocked_raw,
			'document_path' => $document_path,
			'violations'    => 1,
			'first_seen'    => $now,
			'last_seen'     => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return new WP_REST_Response( null, 204 );
}

/**
 * CSP directives this plugin knows how to build an allowlist for.
 *
 * @return string[]
 */
function lkc_known_csp_directives() {
	return array(
		'default-src',
		'script-src',
		'style-src',
		'img-src',
		'media-src',
		'font-src',
		'connect-src',
		'frame-src',
		'child-src',
		'worker-src',
		'manifest-src',
		'object-src',
		'base-uri',
		'form-action',
		'frame-ancestors',
	);
}

/**
 * Map a reported directive onto the base directive this plugin emits.
 *
 * Browsers report the effective directive, so `script-src-elem`,
 * `style-src-attr` and `script-src-inline` all belong to `script-src` and
 * `style-src`. Anything outside the known list returns an empty string rather
 * than being invented, so a malformed report cannot introduce a new directive.
 *
 * @param string $directive Reported directive name.
 * @return string Base directive name, or an empty string when unknown.
 */
function lkc_csp_base_directive( $directive ) {
	$directive = strtolower( trim( (string) $directive ) );

	foreach ( array( '-elem', '-attr', '-inline' ) as $suffix ) {
		if ( substr( $directive, -strlen( $suffix ) ) === $suffix ) {
			$directive = substr( $directive, 0, -strlen( $suffix ) );
			break;
		}
	}

	return in_array( $directive, lkc_known_csp_directives(), true ) ? $directive : '';
}

/**
 * Whether a value can be used as a host in a CSP source expression.
 *
 * Keywords such as `inline`, `eval`, `data` and `self` are reported in the same
 * field as URLs but must not become `https://inline`.
 *
 * @param string $host Candidate host.
 * @return bool
 */
function lkc_is_csp_allowlist_host( $host ) {
	$host = strtolower( trim( (string) $host ) );

	if ( '' === $host || strlen( $host ) > 191 ) {
		return false;
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return true;
	}

	/* A registrable host: at least two labels, no scheme, no path, no port. */
	return (bool) preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host );
}

/**
 * Whether an existing directive already permits this host.
 *
 * Covers the exact origin, a scheme-relative origin, a wildcard subdomain, and
 * the blanket `https:` and `*` sources this policy uses for images and media.
 *
 * @param array  $sources Source expressions already configured.
 * @param string $host    Reported host.
 * @return bool
 */
function lkc_csp_source_already_allows( $sources, $host ) {
	$host = strtolower( trim( (string) $host ) );

	foreach ( (array) $sources as $source ) {
		$source = trim( (string) $source );
		if ( '' === $source ) {
			continue;
		}
		if ( '*' === $source || 'https:' === $source ) {
			return true;
		}
		foreach ( array( 'https://', 'http://', '//' ) as $prefix ) {
			if ( 0 === strcasecmp( $source, $prefix . $host ) ) {
				return true;
			}
		}
		if ( preg_match( '#^https?://\*\.(.+)$#i', $source, $matches ) ) {
			$pattern = '/(^|\.)' . preg_quote( strtolower( $matches[1] ), '/' ) . '$/';
			if ( preg_match( $pattern, $host ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Reduce stored CSP report rows to what an allowlist needs.
 *
 * Rows are already aggregated per directive/host/document path, so this only
 * separates real hosts from CSP keywords, folds `-elem`/`-attr` variants into
 * their base directive, and sums the violation counts per pair.
 *
 * @param array $rows Rows with `directive`, `blocked_host` and `violations`.
 * @return array {
 *     @type array  $allowlist  Base directive => host => violation count.
 *     @type array  $keywords   Base directive => keyword => violation count.
 *     @type string $first_seen Earliest first_seen, or an empty string.
 *     @type string $last_seen  Latest last_seen, or an empty string.
 * }
 */
function lkc_group_csp_reports( $rows ) {
	$grouped = array(
		'allowlist'  => array(),
		'keywords'   => array(),
		'first_seen' => '',
		'last_seen'  => '',
	);

	foreach ( (array) $rows as $row ) {
		$row = (array) $row;

		$directive = lkc_csp_base_directive( isset( $row['directive'] ) ? $row['directive'] : '' );
		if ( '' === $directive ) {
			continue;
		}

		$violations = isset( $row['violations'] ) ? absint( $row['violations'] ) : 0;
		$blocked    = strtolower( trim( (string) ( isset( $row['blocked_host'] ) ? $row['blocked_host'] : '' ) ) );

		$bucket = lkc_is_csp_allowlist_host( $blocked ) ? 'allowlist' : 'keywords';
		$key    = ( 'keywords' === $bucket && '' === $blocked ) ? 'unknown' : $blocked;

		if ( ! isset( $grouped[ $bucket ][ $directive ] ) || ! is_array( $grouped[ $bucket ][ $directive ] ) ) {
			$grouped[ $bucket ][ $directive ] = array();
		}
		$previous = isset( $grouped[ $bucket ][ $directive ][ $key ] ) ? absint( $grouped[ $bucket ][ $directive ][ $key ] ) : 0;
		$grouped[ $bucket ][ $directive ][ $key ] = $previous + $violations;

		if ( isset( $row['first_seen'] ) && '' !== (string) $row['first_seen'] ) {
			$value                    = (string) $row['first_seen'];
			$grouped['first_seen']    = ( '' === $grouped['first_seen'] ) ? $value : min( $grouped['first_seen'], $value );
		}
		if ( isset( $row['last_seen'] ) && '' !== (string) $row['last_seen'] ) {
			$value                 = (string) $row['last_seen'];
			$grouped['last_seen']  = ( '' === $grouped['last_seen'] ) ? $value : max( $grouped['last_seen'], $value );
		}
	}

	foreach ( array( 'allowlist', 'keywords' ) as $bucket ) {
		foreach ( $grouped[ $bucket ] as $directive => $entries ) {
			arsort( $grouped[ $bucket ][ $directive ] );
		}
		ksort( $grouped[ $bucket ] );
	}

	return $grouped;
}

/**
 * Explain a CSP keyword violation in terms of the fix that applies to it.
 *
 * @param string $keyword    Reported keyword, e.g. `inline`, `eval`, `data`.
 * @param string $directive  Base directive the keyword was reported against.
 * @param int    $violations Number of times it was reported.
 * @return string
 */
function lkc_explain_csp_keyword( $keyword, $directive, $violations ) {
	$keyword = strtolower( trim( (string) $keyword ) );
	$count   = sprintf( '%s pelanggaran', number_format_i18n( absint( $violations ) ) );

	switch ( $keyword ) {
		case 'inline':
			return sprintf( "'inline' pada %1\$s (%2\$s): bukan domain yang bisa diizinkan. Nyalakan `lkc_csp_strict` agar elemen inline mendapat nonce, atau pindahkan kode itu ke berkas eksternal.", $directive, $count );
		case 'eval':
			return sprintf( "'eval' pada %1\$s (%2\$s): ada kode memakai eval() atau new Function(). Menambahkan 'unsafe-eval' membuka kembali lubang yang CSP tutup; cari plugin atau tema yang memakainya.", $directive, $count );
		case 'data':
			return sprintf( "'data' pada %1\$s (%2\$s): sumber dimuat dari data: URI. Bila memang perlu, tambahkan 'data:' ke direktif itu secara eksplisit.", $directive, $count );
		case 'self':
		case 'none':
			return sprintf( "'%1\$s' pada %2\$s (%3\$s): dilaporkan sebagai kata kunci, bukan URL. Periksa path dokumen pada baris tabel di atas.", $keyword, $directive, $count );
		case 'unknown':
			return sprintf( 'Laporan tanpa sumber teridentifikasi pada %1$s (%2$s).', $directive, $count );
		default:
			return sprintf( "Sumber terblokir '%1\$s' pada %2\$s (%3\$s) bukan nama host yang valid.", $keyword, $directive, $count );
	}
}

/**
 * Build a ready-to-paste filter snippet from collected CSP reports.
 *
 * Hosts the current policy already permits are left out, and CSP keywords are
 * translated into the action that actually resolves them rather than being
 * offered as domains to allowlist.
 *
 * @param array $grouped            Output of lkc_group_csp_reports().
 * @param array $current_directives Directive list currently in effect.
 * @return string PHP snippet; never empty, it explains itself when there is nothing to add.
 */
function lkc_build_csp_allowlist_snippet( $grouped, $current_directives = array() ) {
	$grouped   = is_array( $grouped ) ? $grouped : array();
	$current   = is_array( $current_directives ) ? $current_directives : array();
	$allowlist = isset( $grouped['allowlist'] ) && is_array( $grouped['allowlist'] ) ? $grouped['allowlist'] : array();
	$keywords  = isset( $grouped['keywords'] ) && is_array( $grouped['keywords'] ) ? $grouped['keywords'] : array();

	$own_host        = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$has_default_src = isset( $current['default-src'] ) && is_array( $current['default-src'] );

	$assignments = array();
	$comments    = array();
	$skipped     = 0;

	foreach ( $allowlist as $directive => $hosts ) {
		/* Browsers fall back to default-src when the specific directive is absent. */
		if ( isset( $current[ $directive ] ) && is_array( $current[ $directive ] ) ) {
			$existing = $current[ $directive ];
		} else {
			$existing = $has_default_src ? $current['default-src'] : array();
		}

		foreach ( (array) $hosts as $host => $violations ) {
			$host = (string) $host;

			if ( '' !== $own_host && $host === $own_host ) {
				$comments[] = sprintf(
					'/* https://%1$s pada %2$s adalah situs ini sendiri: hampir pasti skrip atau gaya inline, bukan domain untuk diizinkan. */',
					$host,
					$directive
				);
				continue;
			}
			if ( lkc_csp_source_already_allows( $existing, $host ) ) {
				++$skipped;
				continue;
			}

			$assignments[] = "\t" . sprintf(
				'$policy[%1$s][] = %2$s; // %3$s pelanggaran',
				"'" . $directive . "'",
				"'https://" . $host . "'",
				number_format_i18n( absint( $violations ) )
			);
		}
	}

	foreach ( $keywords as $directive => $entries ) {
		foreach ( (array) $entries as $keyword => $violations ) {
			$comments[] = '/* ' . lkc_explain_csp_keyword( (string) $keyword, (string) $directive, absint( $violations ) ) . ' */';
		}
	}

	$window = '';
	if ( ! empty( $grouped['first_seen'] ) && ! empty( $grouped['last_seen'] ) ) {
		$window = sprintf(
			' antara %1$s dan %2$s',
			substr( (string) $grouped['first_seen'], 0, 10 ),
			substr( (string) $grouped['last_seen'], 0, 10 )
		);
	}

	$snippet   = array();
	$snippet[] = 'add_filter( \'lkc_content_security_policy\', function ( $policy ) {';
	$snippet[] = "\t" . '/* Disusun dari laporan CSP' . $window . ' (Alat → Log Keamanan → CSP). */';
	$snippet[] = "\t" . '/* Hapus baris untuk domain yang tidak Anda kenali: setiap baris melebarkan kebijakan. */';

	if ( ! $assignments && ! $comments ) {
		$snippet[] = "\t" . '/* Tidak ada domain baru yang perlu diizinkan' . ( $skipped ? ' — ' . number_format_i18n( $skipped ) . ' sumber terlapor sudah diizinkan kebijakan saat ini' : '' ) . '. */';
	}

	if ( $assignments ) {
		$snippet = array_merge( $snippet, $assignments );
	}
	if ( $comments ) {
		$snippet[] = '';
		$snippet   = array_merge(
			$snippet,
			array_map(
				static function ( $comment ) {
					return "\t" . $comment;
				},
				$comments
			)
		);
	}

	$snippet[] = "\t" . 'return $policy;';
	$snippet[] = '} );';

	return implode( "\n", $snippet );
}

/** Remove audit entries and stale violation reports on a daily schedule. */
function lkc_prune_security_logs() {
	global $wpdb;

	/**
	 * Filter how long audit entries are kept, in days. Return 0 to keep them all.
	 *
	 * @param int $days Retention period.
	 */
	$audit_days = absint( apply_filters( 'lkc_audit_log_retention_days', 90 ) );
	if ( $audit_days ) {
		$audit_table = lkc_audit_log_table_name();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$audit_table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - ( $audit_days * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * Filter how long an unseen violation fingerprint is kept, in days.
	 *
	 * @param int $days Retention period.
	 */
	$report_days = absint( apply_filters( 'lkc_csp_report_retention_days', 30 ) );
	if ( $report_days ) {
		$report_table = lkc_csp_report_table_name();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$report_table} WHERE last_seen < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - ( $report_days * DAY_IN_SECONDS ) )
			)
		);
	}
}
add_action( 'lkc_prune_security_logs', 'lkc_prune_security_logs' );

/** Schedule the daily pruning job once. */
function lkc_maybe_schedule_security_pruning() {
	if ( ! wp_next_scheduled( 'lkc_prune_security_logs' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lkc_prune_security_logs' );
	}
}
add_action( 'init', 'lkc_maybe_schedule_security_pruning' );

/** Clear the scheduled job when the plugin is deactivated. */
function lkc_clear_security_pruning_schedule() {
	$timestamp = wp_next_scheduled( 'lkc_prune_security_logs' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'lkc_prune_security_logs' );
	}
}
add_action( 'deactivate_' . plugin_basename( LKC_PLUGIN_FILE ), 'lkc_clear_security_pruning_schedule' );

/** Register the log viewer under Tools. */
function lkc_register_security_log_page() {
	add_management_page(
		__( 'Log Keamanan', 'layar-katalog-core' ),
		__( 'Log Keamanan', 'layar-katalog-core' ),
		'manage_options',
		'lkc-security-log',
		'lkc_render_security_log_page'
	);
}
add_action( 'admin_menu', 'lkc_register_security_log_page' );

/**
 * URL of one tab on the security log screen.
 *
 * @param string $tab Tab slug.
 * @return string Escaped admin URL.
 */
function lkc_security_log_tab_url( $tab ) {
	return add_query_arg(
		array(
			'page'   => 'lkc-security-log',
			'lkc_tab' => sanitize_key( $tab ),
		),
		admin_url( 'tools.php' )
	);
}

/** Empty the audit log after a capability and nonce check. */
function lkc_handle_clear_audit_log() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Anda tidak memiliki izin untuk mengelola log keamanan.', 'layar-katalog-core' ) );
	}
	check_admin_referer( 'lkc_clear_audit_log' );

	global $wpdb;
	$deleted = $wpdb->query( 'DELETE FROM ' . lkc_audit_log_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	lkc_write_audit_log( 'audit_log_cleared', array( 'detail' => sprintf( '%d entri dihapus', absint( $deleted ) ) ) );

	wp_safe_redirect( add_query_arg( 'lkc_notice', 'audit_cleared', lkc_security_log_tab_url( 'audit' ) ) );
	exit;
}
add_action( 'admin_post_lkc_clear_audit_log', 'lkc_handle_clear_audit_log' );

/** Empty the collected violation reports after a capability and nonce check. */
function lkc_handle_clear_csp_reports() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Anda tidak memiliki izin untuk mengelola log keamanan.', 'layar-katalog-core' ) );
	}
	check_admin_referer( 'lkc_clear_csp_reports' );

	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . lkc_csp_report_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	lkc_write_audit_log( 'csp_reports_cleared' );

	wp_safe_redirect( add_query_arg( 'lkc_notice', 'csp_cleared', lkc_security_log_tab_url( 'csp' ) ) );
	exit;
}
add_action( 'admin_post_lkc_clear_csp_reports', 'lkc_handle_clear_csp_reports' );

/** Render the two-tab security log screen. */
function lkc_render_security_log_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Anda tidak memiliki izin untuk mengelola log keamanan.', 'layar-katalog-core' ) );
	}

	global $wpdb;

	/* Make the screen usable on a fresh install where plugins_loaded has not run yet. */
	$audit_table  = lkc_audit_log_table_name();
	$report_table = lkc_csp_report_table_name();
	$audit_ready  = $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $audit_table ) ) );
	$report_ready = $report_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $report_table ) ) );
	if ( ! $audit_ready || ! $report_ready ) {
		lkc_install_security_tables();
		$audit_ready  = $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $audit_table ) ) );
		$report_ready = $report_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $report_table ) ) );
	}

	$tab = isset( $_GET['lkc_tab'] ) ? sanitize_key( wp_unslash( $_GET['lkc_tab'] ) ) : 'audit';
	if ( ! in_array( $tab, array( 'audit', 'csp' ), true ) ) {
		$tab = 'audit';
	}

	$notices = array(
		'audit_cleared' => __( 'Log audit dikosongkan.', 'layar-katalog-core' ),
		'csp_cleared'   => __( 'Laporan pelanggaran CSP dikosongkan.', 'layar-katalog-core' ),
	);
	$notice_key = isset( $_GET['lkc_notice'] ) ? sanitize_key( wp_unslash( $_GET['lkc_notice'] ) ) : '';

	$csp_mode = (string) apply_filters( 'lkc_csp_mode', 'report-only' );

	if ( ! $audit_ready || ! $report_ready ) {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Log Keamanan', 'layar-katalog-core' ); ?></h1>
			<div class="notice notice-error"><p><?php esc_html_e( 'Tabel log keamanan belum dapat dibuat. Periksa izin CREATE TABLE pengguna database WordPress, lalu muat ulang halaman ini.', 'layar-katalog-core' ); ?></p></div>
		</div>
		<?php
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Log Keamanan', 'layar-katalog-core' ); ?></h1>

		<?php if ( isset( $notices[ $notice_key ] ) ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notices[ $notice_key ] ); ?></p></div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper">
			<a class="nav-tab <?php echo 'audit' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( lkc_security_log_tab_url( 'audit' ) ); ?>"><?php esc_html_e( 'Audit', 'layar-katalog-core' ); ?></a>
			<a class="nav-tab <?php echo 'csp' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( lkc_security_log_tab_url( 'csp' ) ); ?>"><?php esc_html_e( 'Laporan CSP', 'layar-katalog-core' ); ?></a>
		</h2>

		<?php if ( 'audit' === $tab ) : ?>
			<p><?php esc_html_e( 'Aksi administratif yang bersifat destruktif atau mengubah kebijakan keamanan dicatat di sini. Alamat IP dan user agent disimpan sebagai hash, bukan nilai asli.', 'layar-katalog-core' ); ?></p>
			<?php
			$per_page = 50;
			$paged    = isset( $_GET['paged'] ) ? max( 1, min( 500, absint( wp_unslash( $_GET['paged'] ) ) ) ) : 1;
			$offset   = ( $paged - 1 ) * $per_page;
			$total    = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT actor_login, action, object_type, object_id, detail, ip_hash, created_at FROM {$audit_table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$per_page,
					$offset
				)
			);
			?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Waktu (UTC)', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'Aksi', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'Aktor', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'Objek', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'IP (hash)', 'layar-katalog-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Belum ada entri.', 'layar-katalog-core' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row->created_at ); ?></td>
								<td><code><?php echo esc_html( (string) $row->action ); ?></code></td>
								<td><?php echo $row->actor_login ? esc_html( (string) $row->actor_login ) : '<em>' . esc_html__( 'anonim', 'layar-katalog-core' ) . '</em>'; ?></td>
								<td>
									<?php
									$object = trim( (string) $row->object_type . ( $row->object_id ? ' #' . absint( $row->object_id ) : '' ) );
									echo $object ? esc_html( $object ) : '—';
									?>
								</td>
								<td><?php echo $row->detail ? esc_html( (string) $row->detail ) : '—'; ?></td>
								<td><code><?php echo esc_html( substr( (string) $row->ip_hash, 0, 12 ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / $per_page );
			if ( $pages > 1 ) :
				?>
				<p class="tablenav">
					<?php if ( $paged > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, lkc_security_log_tab_url( 'audit' ) ) ); ?>"><?php esc_html_e( '← Lebih baru', 'layar-katalog-core' ); ?></a>
					<?php endif; ?>
					<span class="displaying-num"><?php echo esc_html( sprintf( /* translators: 1: current page number, 2: total pages, 3: total entries. */ __( 'Halaman %1$d dari %2$d (%3$s entri)', 'layar-katalog-core' ), $paged, $pages, number_format_i18n( $total ) ) ); ?></span>
					<?php if ( $paged < $pages ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, lkc_security_log_tab_url( 'audit' ) ) ); ?>"><?php esc_html_e( 'Lebih lama →', 'layar-katalog-core' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem" onsubmit="return confirm('<?php echo esc_js( __( 'Hapus seluruh log audit?', 'layar-katalog-core' ) ); ?>');">
				<input type="hidden" name="action" value="lkc_clear_audit_log">
				<?php wp_nonce_field( 'lkc_clear_audit_log' ); ?>
				<?php submit_button( __( 'Hapus log audit', 'layar-katalog-core' ), 'delete', 'submit', false ); ?>
			</form>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %d: retention period in days. */ __( 'Entri lebih tua dari %d hari dihapus otomatis setiap hari oleh WP-Cron.', 'layar-katalog-core' ), absint( apply_filters( 'lkc_audit_log_retention_days', 90 ) ) ) ); ?></p>

		<?php else : ?>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: current CSP delivery mode. */
						__( 'Content-Security-Policy aktif dalam mode <code>%s</code>. Mode <code>report-only</code> melaporkan pelanggaran tanpa memblokir apa pun, sehingga daftar domain yang benar-benar dibutuhkan situs dapat dibaca dari tabel ini sebelum kebijakan ditegakkan.', 'layar-katalog-core' ),
						esc_html( $csp_mode )
					)
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Untuk menegakkan kebijakan, kembalikan "enforce" pada filter lkc_csp_mode setelah semua domain yang dibutuhkan ditambahkan lewat filter lkc_content_security_policy. Laporan hanya disimpan per gabungan directive, sumber terblokir, dan path dokumen; query string tidak disimpan.', 'layar-katalog-core' ); ?></p>
			<?php
			$report_rows = $wpdb->get_results( "SELECT directive, blocked_host, blocked_uri, document_path, violations, first_seen, last_seen FROM {$report_table} ORDER BY violations DESC, last_seen DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$report_total = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$report_table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Directive', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'Sumber terblokir', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'Path dokumen', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'Jumlah', 'layar-katalog-core' ); ?></th>
						<th><?php esc_html_e( 'Terakhir (UTC)', 'layar-katalog-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $report_rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Belum ada laporan pelanggaran. Buka beberapa halaman situs dengan browser Chrome atau Firefox untuk mengumpulkannya.', 'layar-katalog-core' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $report_rows as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $row->directive ); ?></code></td>
								<td>
									<strong><?php echo esc_html( $row->blocked_host ? (string) $row->blocked_host : '—' ); ?></strong>
									<?php if ( $row->blocked_uri && $row->blocked_uri !== $row->blocked_host ) : ?>
										<br><code style="word-break:break-all"><?php echo esc_html( (string) $row->blocked_uri ); ?></code>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $row->document_path ? (string) $row->document_path : '—' ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( absint( $row->violations ) ) ); ?></td>
								<td><?php echo esc_html( (string) $row->last_seen ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html( sprintf( /* translators: 1: number of distinct violation patterns stored, 2: the ceiling the collector keeps. */ __( '%1$s pola pelanggaran berbeda tersimpan (maksimum %2$s; sisanya tetap dihitung pada pola yang sudah ada).', 'layar-katalog-core' ), number_format_i18n( $report_total ), number_format_i18n( absint( apply_filters( 'lkc_csp_report_max_rows', 500 ) ) ) ) ); ?></p>

			<?php
			$csp_grouped = lkc_group_csp_reports( $report_rows );
			$csp_snippet = lkc_build_csp_allowlist_snippet( $csp_grouped, lkc_get_csp_directives() );
			$csp_strict  = lkc_is_csp_strict();
			?>
			<h2><?php esc_html_e( 'Susun allowlist dari laporan ini', 'layar-katalog-core' ); ?></h2>
			<p class="description">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: number of stored violation patterns. */
						__( 'Snippet di bawah disusun dari %s pola pelanggaran yang tersimpan. Salin ke <code>wp-content/mu-plugins/csp-allowlist.php</code> atau ke <code>functions.php</code> child theme, periksa setiap baris, lalu ubah filter <code>lkc_csp_mode</code> menjadi <code>enforce</code>.', 'layar-katalog-core' ),
						esc_html( number_format_i18n( $report_total ) )
					)
				);
				?>
			</p>
			<textarea readonly="readonly" rows="14" class="large-text code" aria-label="<?php esc_attr_e( 'Snippet filter allowlist CSP hasil laporan', 'layar-katalog-core' ); ?>"><?php echo esc_textarea( $csp_snippet ); ?></textarea>
			<p class="description">
				<?php
				if ( $csp_strict ) {
					echo wp_kses_post( __( 'Filter <code>lkc_csp_strict</code> <strong>aktif</strong>: <code>\'unsafe-inline\'</code> sudah dilepas, dan setiap elemen inline — termasuk yang dicetak WordPress core dan kode iklan administrator — membawa nonce permintaan ini. Konsekuensinya HTML tidak boleh lagi disimpan cache halaman penuh.', 'layar-katalog-core' ) );
				} else {
					echo wp_kses_post( __( 'Filter <code>lkc_csp_strict</code> <strong>nonaktif</strong>: <code>\'unsafe-inline\'</code> masih dipakai. Nyalakan dengan <code>add_filter( \'lkc_csp_strict\', \'__return_true\' );</code> untuk melepasnya, dan uji dulu sambil tetap di mode <code>report-only</code>.', 'layar-katalog-core' ) );
				}
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Setiap baris snippet melebarkan kebijakan: hanya tambahkan domain yang Anda kenali dan memang dibutuhkan situs. Laporan dengan sumber "inline" atau "eval" tidak bisa diselesaikan lewat allowlist — keduanya dijelaskan sebagai komentar di dalam snippet.', 'layar-katalog-core' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem" onsubmit="return confirm('<?php echo esc_js( __( 'Hapus seluruh laporan pelanggaran CSP?', 'layar-katalog-core' ) ); ?>');">
				<input type="hidden" name="action" value="lkc_clear_csp_reports">
				<?php wp_nonce_field( 'lkc_clear_csp_reports' ); ?>
				<?php submit_button( __( 'Hapus laporan CSP', 'layar-katalog-core' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
