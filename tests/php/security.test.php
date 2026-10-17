<?php
/**
 * Security regression tests for the Layar Katalog core plugin and theme.
 *
 * Runs the shipped source against stubbed WordPress primitives. Exits non-zero
 * when any assertion fails so CI can gate on it.
 *
 * @package Layar_Katalog_Core
 */

require __DIR__ . '/bootstrap.php';

/* ================================================================== *
 * Origin normalisation (CORS building block)
 * ================================================================== */
t_section( 'lkc_normalize_origin()' );
t_assert( 'lowercases and strips path/query', 'https://app.example.test:8443' === lkc_normalize_origin( 'https://APP.Example.TEST:8443/katalog/?a=1' ), lkc_normalize_origin( 'https://APP.Example.TEST:8443/katalog/?a=1' ) );
t_assert( 'default https port dropped', 'https://example.test' === lkc_normalize_origin( 'https://example.test:443/x' ), lkc_normalize_origin( 'https://example.test:443/x' ) );
t_assert( 'default http port dropped', 'http://example.test' === lkc_normalize_origin( 'http://example.test:80/x' ), lkc_normalize_origin( 'http://example.test:80/x' ) );
t_assert( 'non-default port kept', 'https://example.test:8443' === lkc_normalize_origin( 'https://example.test:8443' ) );
t_assert( 'file: scheme rejected', '' === lkc_normalize_origin( 'file:///etc/passwd' ), lkc_normalize_origin( 'file:///etc/passwd' ) );
t_assert( 'schemeless junk rejected', '' === lkc_normalize_origin( 'not a url at all' ), var_export( lkc_normalize_origin( 'not a url at all' ), true ) );
t_assert( 'empty string rejected', '' === lkc_normalize_origin( '' ) );
t_assert( 'javascript: URL rejected', '' === lkc_normalize_origin( 'javascript:alert(1)//example.test' ), lkc_normalize_origin( 'javascript:alert(1)//example.test' ) );
t_assert( 'bare host without scheme rejected', '' === lkc_normalize_origin( 'example.test' ), lkc_normalize_origin( 'example.test' ) );

/* ================================================================== *
 * CORS allowlist
 * ================================================================== */
t_section( 'lkc_is_allowed_origin() — CORS allowlist' );
t_assert( 'same-origin allowed', true === lkc_is_allowed_origin( 'https://example.test' ) );
t_assert( 'foreign origin blocked', false === lkc_is_allowed_origin( 'https://evil.test' ) );
t_assert( 'subdomain blocked (no wildcard)', false === lkc_is_allowed_origin( 'https://www.example.test' ) );
t_assert( 'scheme downgrade blocked', false === lkc_is_allowed_origin( 'http://example.test' ) );
t_assert( 'empty origin blocked', false === lkc_is_allowed_origin( '' ) );
t_assert( 'suffix-match attack blocked', false === lkc_is_allowed_origin( 'https://notexample.test' ) );
t_assert( 'prefix-match attack blocked', false === lkc_is_allowed_origin( 'https://example.test.evil.test' ) );
t_assert( 'case variation still matches', true === lkc_is_allowed_origin( lkc_normalize_origin( 'HTTPS://EXAMPLE.TEST' ) ) );
add_filter( 'lkc_allowed_cors_origins', function ( $o ) { $o[] = 'https://cdn.partner.test'; return $o; } );
t_assert( 'filter can extend the allowlist', true === lkc_is_allowed_origin( 'https://cdn.partner.test' ) );
t_assert( 'unknown origin still blocked after filter', false === lkc_is_allowed_origin( 'https://other.test' ) );

/* ================================================================== *
 * Content-Security-Policy
 * ================================================================== */
t_section( 'Content-Security-Policy' );
$csp = lkc_get_content_security_policy();
t_assert( 'policy is a non-empty header string', is_string( $csp ) && '' !== $csp );
foreach ( array( "default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'" ) as $expected ) {
	t_assert( "policy contains: {$expected}", false !== strpos( $csp, $expected ), $csp );
}
t_assert( 'report-uri points at the collector', false !== strpos( $csp, 'report-uri https://example.test/wp-json/lkc/v1/csp-report' ), $csp );
t_assert( 'at least 10 directives', substr_count( $csp, ';' ) >= 9, (string) substr_count( $csp, ';' ) );

$hdr = lkc_get_content_security_policy_header();
t_assert( 'default mode is report-only', array( 'Content-Security-Policy-Report-Only' ) === array_keys( $hdr ), json_encode( array_keys( $hdr ) ) );
t_assert( 'report-only value equals the policy', $csp === reset( $hdr ) );
add_filter( 'lkc_csp_mode', function () { return 'enforce'; } );
t_assert( 'enforce mode switches the header name', array( 'Content-Security-Policy' ) === array_keys( lkc_get_content_security_policy_header() ), json_encode( array_keys( lkc_get_content_security_policy_header() ) ) );
add_filter( 'lkc_csp_mode', function () { return 'off'; }, 20 );
t_assert( 'off mode sends nothing', array() === lkc_get_content_security_policy_header() );

t_section( 'a hostile CSP filter cannot inject a second directive' );
$GLOBALS['__filters']['lkc_content_security_policy'][] = function ( $p ) {
	$p['script-src'] = array( "'self'; report-uri https://evil.test/steal" );
	$p['img-src']    = array( 'https://ok.test', "\nframe-ancestors *", 'has;semicolon' );
	$p["bad\nkey"]   = array( "'self'" );
	$p['object-src'] = array( '*' );
	return $p;
};
$hostile = lkc_get_content_security_policy();
t_assert( 'semicolon injection is dropped', false === strpos( $hostile, 'report-uri https://evil.test/steal' ), $hostile );
t_assert( 'newline injection is dropped', false === strpos( $hostile, 'frame-ancestors *' ), $hostile );
t_assert( 'valid source survives alongside the junk', false !== strpos( $hostile, 'https://ok.test' ), $hostile );
t_assert( 'malformed directive name is dropped', false === strpos( $hostile, 'bad' ), $hostile );

/* ================================================================== *
 * Personalisation hint consumed by the service worker
 * ================================================================== */
t_section( 'personalisation cache hint' );
$GLOBALS['__user_id'] = 7;
t_assert( 'signed-in response is flagged personalized', true === lkc_is_personalized_response() );
$GLOBALS['__user_id']    = 0;
$GLOBALS['__is_preview'] = true;
t_assert( 'preview is flagged personalized', true === lkc_is_personalized_response() );
$GLOBALS['__is_preview'] = false;
t_assert( 'anonymous normal page is not personalized', false === lkc_is_personalized_response() );

/* ================================================================== *
 * Rate limiting
 * ================================================================== */
t_section( 'rate limiter' );
$GLOBALS['__transients'] = array();
$identity                = lkc_get_rate_limit_identity();
t_assert( 'anonymous identity is a salted hash, not a raw IP', 34 === strlen( $identity ) && 0 === strpos( $identity, 'ip' ) && false === strpos( $identity, '203.0.113.9' ), $identity );
$GLOBALS['__user_id'] = 3;
t_assert( 'signed-in identity is keyed by user id', 'u3' === lkc_get_rate_limit_identity(), lkc_get_rate_limit_identity() );
$GLOBALS['__user_id']    = 0;
$first                    = lkc_get_rate_limit_identity();
$_SERVER['REMOTE_ADDR']   = '198.51.100.4';
t_assert( 'different IPs land in different buckets', $first !== lkc_get_rate_limit_identity() );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$req        = new FakeRequest();
$req->route = '/lkc/v1/catalog/search';
t_assert( 'GET maps to the read bucket', 'read' === lkc_rest_rate_limit_buckets( $req ) );
$req->method = 'POST';
t_assert( 'POST maps to the write bucket', 'write' === lkc_rest_rate_limit_buckets( $req ) );
$req->route = '/wp/v2/posts';
t_assert( 'core route gets no bucket', '' === lkc_rest_rate_limit_buckets( $req ) );
$req->route  = '/lkc/v1/csp-report';
$req->method = 'POST';
t_assert( 'CSP report route is exempt', '' === lkc_rest_rate_limit_buckets( $req ) );
t_assert( 'plugin route detected', true === lkc_is_plugin_rest_request( ( function () { $r = new FakeRequest(); $r->route = '/lkc/v1/push/key'; return $r; } )() ) );
t_assert( 'core route not claimed', false === lkc_is_plugin_rest_request( ( function () { $r = new FakeRequest(); $r->route = '/wp/v2/posts'; return $r; } )() ) );

$GLOBALS['__transients'] = array();
$limited_at             = 0;
$out                    = null;
for ( $i = 1; $i <= 14; $i++ ) {
	$w         = new FakeRequest();
	$w->route  = '/lkc/v1/push/subscribe';
	$w->method = 'POST';
	$out       = lkc_enforce_rest_rate_limits( null, null, $w );
	if ( $out instanceof WP_REST_Response && 429 === $out->get_status() ) {
		$limited_at = $i;
		break;
	}
}
t_assert( 'write limit (10/min) blocks the 11th request', 11 === $limited_at, "blocked at {$limited_at}" );
t_assert( '429 carries Retry-After', ! empty( $out->headers['Retry-After'] ), json_encode( $out->headers ) );
t_assert( '429 is never cached', 'no-store' === ( $out->headers['Cache-Control'] ?? '' ), json_encode( $out->headers ) );

$GLOBALS['__transients'] = array();
$reads_ok                = 0;
for ( $i = 1; $i <= 60; $i++ ) {
	$g         = new FakeRequest();
	$g->route  = '/lkc/v1/catalog/search';
	$g->method = 'GET';
	if ( ! ( lkc_enforce_rest_rate_limits( null, null, $g ) instanceof WP_REST_Response ) ) {
		$reads_ok++;
	}
}
t_assert( 'read limit allows exactly 60/min', 60 === $reads_ok, (string) $reads_ok );

/* ================================================================== *
 * Request body ceiling
 * ================================================================== */
t_section( 'oversized request guard' );
$big         = new FakeRequest();
$big->route  = '/lkc/v1/push/subscribe';
$big->method = 'POST';
$big->set_header( 'content-length', (string) ( 8 * KB_IN_BYTES + 1 ) );
$err = lkc_reject_oversized_rest_body( null, null, $big );
t_assert( 'oversized Content-Length is refused', $err instanceof WP_REST_Response && 413 === $err->get_status(), $err instanceof WP_REST_Response ? (string) $err->get_status() : get_class( $err ) );
t_assert( '413 uses a stable error code', 'lkc_payload_too_large' === ( $err->data['code'] ?? '' ), json_encode( $err->data ?? null ) );

$small         = new FakeRequest();
$small->route  = '/lkc/v1/push/subscribe';
$small->method = 'POST';
$small->set_header( 'content-length', '512' );
$small->body = str_repeat( 'A', 512 );
t_assert( 'small body passes through', null === lkc_reject_oversized_rest_body( null, null, $small ) );

$liar         = new FakeRequest();
$liar->route  = '/lkc/v1/push/subscribe';
$liar->method = 'POST';
$liar->set_header( 'content-length', '10' );
$liar->body = str_repeat( 'A', 9000 );
t_assert( 'lying Content-Length is caught by measuring the body', lkc_reject_oversized_rest_body( null, null, $liar ) instanceof WP_REST_Response );

$get         = new FakeRequest();
$get->route  = '/lkc/v1/catalog/search';
$get->method = 'GET';
$get->set_header( 'content-length', '999999' );
t_assert( 'GET requests are not body-checked', null === lkc_reject_oversized_rest_body( null, null, $get ) );

/* ================================================================== *
 * Audit log
 * ================================================================== */
t_section( 'audit action allowlist' );
foreach ( array( 'push_settings_saved', 'vapid_keys_rotated', 'push_subscriptions_cleared', 'push_subscriptions_pruned', 'push_subscription_rejected', 'ad_code_saved', 'audit_log_cleared', 'csp_reports_cleared' ) as $action ) {
	t_assert( "{$action} is audited", true === lkc_is_audited_action( $action ) );
}
t_assert( 'unknown action is not audited', false === lkc_is_audited_action( 'something_random' ) );
t_assert( 'rating_saved is opt-in only', false === lkc_is_audited_action( 'rating_saved' ) );
t_assert( 'empty action is rejected', false === lkc_is_audited_action( '' ) );

t_section( 'audit log write path' );
$GLOBALS['__user_id'] = 3;
$before               = count( $wpdb->inserts );
$ok                   = lkc_write_audit_log( 'push_settings_saved', array( 'object_type' => 'push_settings', 'detail' => 'enabled on' ) );
t_assert( 'allowed action is written', true === $ok && $before + 1 === count( $wpdb->inserts ) );
$last = end( $wpdb->inserts );
$row  = $last[1] ?? array();
t_assert( 'row goes to the audit table', 'wp_lkc_audit_log' === ( $last[0] ?? '' ), (string) ( $last[0] ?? '' ) );
t_assert( 'actor id recorded', 3 === (int) ( $row['actor_id'] ?? 0 ) );
t_assert( 'actor login recorded', 'editor' === ( $row['actor_login'] ?? '' ), (string) ( $row['actor_login'] ?? '' ) );
t_assert( 'IP stored as salted hash, not raw', 64 === strlen( (string) ( $row['ip_hash'] ?? '' ) ) && false === strpos( (string) ( $row['ip_hash'] ?? '' ), '203.0.113.9' ), (string) ( $row['ip_hash'] ?? '' ) );
t_assert( 'UA stored as salted hash', 64 === strlen( (string) ( $row['ua_hash'] ?? '' ) ) && false === strpos( (string) ( $row['ua_hash'] ?? '' ), 'Mozilla' ), (string) ( $row['ua_hash'] ?? '' ) );
t_assert( 'detail kept', 'enabled on' === ( $row['detail'] ?? '' ), (string) ( $row['detail'] ?? '' ) );
$before = count( $wpdb->inserts );
lkc_write_audit_log( 'not_allowlisted', array() );
t_assert( 'non-allowlisted action is dropped', $before === count( $wpdb->inserts ) );
add_filter( 'lkc_audit_log_actions', function ( $a ) { $a[] = 'rating_saved'; return $a; } );
$before = count( $wpdb->inserts );
lkc_write_audit_log( 'rating_saved', array() );
t_assert( 'filter can opt an action in', $before + 1 === count( $wpdb->inserts ) );

/* ================================================================== *
 * CSP violation collector
 * ================================================================== */
t_section( 'CSP report collector' );
$before = count( $wpdb->inserts );
$req             = new FakeRequest();
$req->route      = '/lkc/v1/csp-report';
$req->method     = 'POST';
$req->body       = wp_json_encode(
	array(
		'csp-report' => array(
			'document-uri'        => 'https://example.test/katalog/?fbclid=secret&preview_nonce=abc',
			'violated-directive'  => "script-src 'self' https://ads.example.net",
			'blocked-uri'         => 'https://ads.example.net/banner.js',
			'effective-directive' => 'script-src',
		),
	)
);
$req->set_header( 'content-type', 'application/csp-report' );
$res = lkc_rest_receive_csp_report( $req );
t_assert( 'valid report answers 204', $res instanceof WP_REST_Response && 204 === $res->get_status(), get_class( $res ) );
t_assert( 'response body is empty (no reflection)', null === $res->data );
t_assert( 'exactly one row aggregated', $before + 1 === count( $wpdb->inserts ), (string) ( count( $wpdb->inserts ) - $before ) );
$last    = end( $wpdb->inserts );
$csp_row = $last[1] ?? array();
t_assert( 'only the directive name is kept', 'script-src' === ( $csp_row['directive'] ?? '' ), (string) ( $csp_row['directive'] ?? '' ) );
t_assert( 'blocked host recorded', 'ads.example.net' === ( $csp_row['blocked_host'] ?? '' ), (string) ( $csp_row['blocked_host'] ?? '' ) );
t_assert( 'document query string is stripped', '/katalog/' === ( $csp_row['document_path'] ?? '' ), (string) ( $csp_row['document_path'] ?? '' ) );
t_assert( 'row key is a bounded hash', 32 === strlen( (string) ( $csp_row['report_hash'] ?? '' ) ), (string) ( $csp_row['report_hash'] ?? '' ) );

$before  = count( $wpdb->inserts );
$queries = count( $wpdb->queries );
$res2    = lkc_rest_receive_csp_report( $req );
t_assert( 'repeat report answers 204', $res2 instanceof WP_REST_Response && 204 === $res2->get_status() );
t_assert( 'repeat report increments instead of inserting', $before === count( $wpdb->inserts ) );
$did_update = false;
foreach ( array_slice( $wpdb->queries, $queries ) as $q ) {
	if ( false !== strpos( (string) $q, 'violations = violations + 1' ) ) {
		$did_update = true;
	}
}
t_assert( 'repeat report bumps the violation counter', $did_update );

$before       = count( $wpdb->inserts );
$csp3         = new FakeRequest();
$csp3->route  = '/lkc/v1/csp-report';
$csp3->method = 'POST';
$csp3->body   = wp_json_encode(
	array(
		'type'               => 'csp-violation',
		'documentURI'        => 'https://example.test/episode/ep-1/',
		'blockedURI'         => 'https://tracker.test/pixel.gif',
		'effectiveDirective' => 'img-src',
	)
);
lkc_rest_receive_csp_report( $csp3 );
$last     = end( $wpdb->inserts );
$csp3_row = $last[1] ?? array();
t_assert( 'CSP3 flat payload parsed', $before + 1 === count( $wpdb->inserts ) && 'img-src' === ( $csp3_row['directive'] ?? '' ) && 'tracker.test' === ( $csp3_row['blocked_host'] ?? '' ), json_encode( $csp3_row ) );

$before       = count( $wpdb->inserts );
$kw           = new FakeRequest();
$kw->route    = '/lkc/v1/csp-report';
$kw->method   = 'POST';
$kw->body     = wp_json_encode( array( 'csp-report' => array( 'document-uri' => 'https://example.test/', 'violated-directive' => 'script-src', 'blocked-uri' => 'inline' ) ) );
lkc_rest_receive_csp_report( $kw );
$last   = end( $wpdb->inserts );
$kw_row = $last[1] ?? array();
t_assert( '"inline" keyword kept as a host label', $before + 1 === count( $wpdb->inserts ) && 'inline' === ( $kw_row['blocked_host'] ?? '' ), json_encode( $kw_row ) );

$before       = count( $wpdb->inserts );
$bad          = new FakeRequest();
$bad->route   = '/lkc/v1/csp-report';
$bad->method  = 'POST';
$bad->body    = str_repeat( 'x', 4000 );
$res3 = lkc_rest_receive_csp_report( $bad );
t_assert( 'oversized report is refused', $res3 instanceof WP_Error && 400 === (int) ( $res3->get_error_data()['status'] ?? 0 ), get_class( $res3 ) );
t_assert( 'oversized report is not stored', $before === count( $wpdb->inserts ) );

$before        = count( $wpdb->inserts );
$nodir         = new FakeRequest();
$nodir->route  = '/lkc/v1/csp-report';
$nodir->method = 'POST';
$nodir->body   = wp_json_encode( array( 'csp-report' => array( 'document-uri' => 'https://example.test/' ) ) );
t_assert( 'report without a directive is refused', lkc_rest_receive_csp_report( $nodir ) instanceof WP_Error );
t_assert( 'report without a directive is not stored', $before === count( $wpdb->inserts ) );

$before       = count( $wpdb->inserts );
$junk         = new FakeRequest();
$junk->route  = '/lkc/v1/csp-report';
$junk->method = 'POST';
$junk->body   = 'not json at all';
t_assert( 'non-JSON body is refused', lkc_rest_receive_csp_report( $junk ) instanceof WP_Error );
t_assert( 'non-JSON body is not stored', $before === count( $wpdb->inserts ) );

/* ================================================================== *
 * Media URL transport policy (F-10)
 * ================================================================== */
t_section( 'media URL policy — video and subtitles' );
t_assert( 'https mp4 accepted', 'https://cdn.example.test/a.mp4' === lkc_sanitize_video_url( 'https://cdn.example.test/a.mp4' ), (string) lkc_sanitize_video_url( 'https://cdn.example.test/a.mp4' ) );
t_assert( 'https webm accepted', 'https://cdn.example.test/a.webm' === lkc_sanitize_video_url( 'https://cdn.example.test/a.webm' ) );
t_assert( 'http mp4 rejected by default', '' === lkc_sanitize_video_url( 'http://cdn.example.test/a.mp4' ), (string) lkc_sanitize_video_url( 'http://cdn.example.test/a.mp4' ) );
t_assert( 'http subtitle rejected by default', '' === lkc_sanitize_subtitle_url( 'http://cdn.example.test/a.vtt' ) );
t_assert( 'https vtt accepted', 'https://cdn.example.test/a.vtt' === lkc_sanitize_subtitle_url( 'https://cdn.example.test/a.vtt' ) );
t_assert( 'https srt accepted', 'https://cdn.example.test/a.srt' === lkc_sanitize_subtitle_url( 'https://cdn.example.test/a.srt' ) );
t_assert( 'non-media extension rejected', '' === lkc_sanitize_video_url( 'https://cdn.example.test/a.exe' ) );
t_assert( 'javascript: URL rejected', '' === lkc_sanitize_video_url( 'javascript:alert(1)//cdn.example.test/a.mp4' ) );
t_assert( 'hostless URL rejected', false === lkc_is_allowed_media_url( 'https:///a.mp4' ) );
add_filter( 'lkc_allow_insecure_media_urls', function () { return true; } );
t_assert( 'filter can opt a site back into http media', 'http://cdn.example.test/a.mp4' === lkc_sanitize_video_url( 'http://cdn.example.test/a.mp4' ), (string) lkc_sanitize_video_url( 'http://cdn.example.test/a.mp4' ) );

t_section( 'media URL policy — host allowlist' );
add_filter( 'lkc_allowed_media_hosts', function () { return array( 'cdn.example.test', 'https://storage.example.test/bucket' ); } );
t_assert( 'allowlisted host accepted', true === lkc_is_allowed_media_url( 'https://cdn.example.test/a.mp4' ) );
t_assert( 'subdomain of an allowlisted host accepted', true === lkc_is_allowed_media_url( 'https://eu.cdn.example.test/a.mp4' ) );
t_assert( 'look-alike prefix rejected', false === lkc_is_allowed_media_url( 'https://notcdn.example.test/a.mp4' ) );
t_assert( 'unrelated host rejected', false === lkc_is_allowed_media_url( 'https://evil.test/a.mp4' ) );
t_assert( 'entry given as a full URL is reduced to its host', true === lkc_is_allowed_media_url( 'https://storage.example.test/a.mp4' ) );
t_assert( 'suffix-match attack rejected', false === lkc_is_allowed_media_url( 'https://xcdn.example.test/a.mp4' ) );

/* ================================================================== *
 * Stale subscription pruning (F-12)
 * ================================================================== */
t_section( 'stale push subscription pruning' );
$wpdb->columns = array( 'subscription_hash', 'endpoint', 'failure_count', 'last_failure_at' );
t_assert( 'failure tracking detected when columns exist', true === lkc_push_failure_tracking_is_available() );

$queries = count( $wpdb->queries );
lkc_mark_push_subscription_failure( 'abc123' );
$marked = array_slice( $wpdb->queries, $queries );
$found  = false;
foreach ( $marked as $q ) {
	if ( false !== strpos( (string) $q, 'failure_count = failure_count + 1' ) && false !== strpos( (string) $q, 'abc123' ) ) {
		$found = true;
	}
}
t_assert( 'a delivery failure increments the counter', $found, json_encode( $marked ) );
t_assert( 'an empty hash is ignored', null === lkc_mark_push_subscription_failure( '' ) );

$queries = count( $wpdb->queries );
lkc_prune_stale_push_subscriptions();
$prunes        = array_slice( $wpdb->queries, $queries );
$has_threshold = false;
$has_cutoff    = false;
foreach ( $prunes as $q ) {
	$q = (string) $q;
	if ( false !== strpos( $q, 'DELETE FROM' ) && false !== strpos( $q, 'failure_count >=' ) ) {
		$has_threshold = true;
	}
	if ( false !== strpos( $q, 'DELETE FROM' ) && false !== strpos( $q, 'last_failure_at <' ) ) {
		$has_cutoff = true;
	}
}
t_assert( 'prune deletes rows over the failure threshold', $has_threshold, json_encode( $prunes ) );
t_assert( 'prune deletes rows that failed too long ago', $has_cutoff, json_encode( $prunes ) );
t_assert( 'prune never touches rows that have not failed', false === strpos( json_encode( $prunes ), 'last_failure_at IS NULL' ) );

$queries = count( $wpdb->queries );
lkc_maybe_schedule_push_pruning();
t_assert( 'prune job is scheduled once', ! empty( $GLOBALS['__scheduled']['lkc_prune_stale_push_subscriptions'] ), json_encode( $GLOBALS['__scheduled'] ?? array() ) );
$scheduled_at = $GLOBALS['__scheduled']['lkc_prune_stale_push_subscriptions'];
lkc_maybe_schedule_push_pruning();
t_assert( 'a second call does not reschedule', $scheduled_at === $GLOBALS['__scheduled']['lkc_prune_stale_push_subscriptions'] );
lkc_clear_push_pruning_schedule();
t_assert( 'deactivation clears the schedule', empty( $GLOBALS['__scheduled']['lkc_prune_stale_push_subscriptions'] ) );

/* ================================================================== *
 * VAPID private key at rest (audit point 9)
 * ================================================================== */
t_section( 'VAPID private key encryption at rest' );
if ( ! lkc_can_encrypt_secrets() ) {
	echo "SKIP  openssl AES-256-GCM is unavailable in this PHP build; at-rest encryption falls back to plaintext\n";
	t_assert( 'encryption degrades to plaintext instead of failing', 'plain' === lkc_encrypt_secret( 'plain' ) );
} else {
	$secret    = 'VAPID-private-key-material-0123456789';
	$envelope  = lkc_encrypt_secret( $secret );
	t_assert( 'stored value is an envelope', 0 === strpos( $envelope, lkc_secret_envelope_prefix() ), substr( $envelope, 0, 24 ) );
	t_assert( 'plaintext does not appear in the stored value', false === strpos( $envelope, $secret ) );
	t_assert( 'plaintext is not base64 of the stored value either', false === strpos( $envelope, base64_encode( $secret ) ) );
	t_assert( 'roundtrip returns the original secret', $secret === lkc_decrypt_secret( $envelope ) );
	t_assert( 'two encryptions differ (random IV)', lkc_encrypt_secret( $secret ) !== lkc_encrypt_secret( $secret ) );
	t_assert( 'legacy plaintext value still reads back', $secret === lkc_decrypt_secret( $secret ) );
	t_assert( 'empty value stays empty', '' === lkc_decrypt_secret( '' ) );
	t_assert( 'malformed envelope is rejected', '' === lkc_decrypt_secret( 'lkc1:not-a-valid-envelope' ) );

	$parts    = explode( ':', $envelope );
	$parts[3][0] = ( 'A' === $parts[3][0] ) ? 'B' : 'A';
	t_assert( 'tampered ciphertext is rejected (GCM auth)', '' === lkc_decrypt_secret( implode( ':', $parts ) ) );

	$GLOBALS['__salt_override'] = 'ROTATED-SALT-';
	t_assert( 'envelope is unreadable after the salts change', true === lkc_secret_is_unreadable( $envelope ) );
	$GLOBALS['__options']['lkc_push_options'] = array(
		'enabled'     => true,
		'subject'     => 'mailto:admin@example.test',
		'public_key'  => 'PUBLIC',
		'private_key' => $envelope,
	);
	t_assert( 'settings expose no key when it cannot be decrypted', '' === lkc_get_push_settings()['private_key'] );
	unset( $GLOBALS['__salt_override'] );
	t_assert( 'settings expose the key again once the salts match', $secret === lkc_get_push_settings()['private_key'] );
	t_assert( 'a legacy plaintext value is not reported unreadable', false === lkc_secret_is_unreadable( 'legacy-plaintext' ) );

	$GLOBALS['__options']['lkc_push_options'] = array(
		'enabled'     => false,
		'subject'     => 'mailto:admin@example.test',
		'public_key'  => 'PUBLIC',
		'private_key' => $secret,
	);
	lkc_maybe_encrypt_stored_vapid_key();
	$stored = $GLOBALS['__options']['lkc_push_options']['private_key'];
	t_assert( 'legacy key is migrated to an envelope on admin_init', 0 === strpos( (string) $stored, lkc_secret_envelope_prefix() ), (string) $stored );
	t_assert( 'public key is left untouched (it is not a secret)', 'PUBLIC' === $GLOBALS['__options']['lkc_push_options']['public_key'] );
	t_assert( 'migration is idempotent', ( function () use ( $stored ) { lkc_maybe_encrypt_stored_vapid_key(); return $stored === $GLOBALS['__options']['lkc_push_options']['private_key']; } )() );
	t_assert( 'the migrated key still decrypts', $secret === lkc_get_push_settings()['private_key'] );
}

/* ================================================================== *
 * Theme filters
 * ================================================================== */
t_section( 'theme — Google Fonts CSP filter' );
$base = array(
	'style-src' => array( "'self'", "'unsafe-inline'" ),
	'font-src'  => array( "'self'", 'data:' ),
);
t_assert( 'no font selected: policy untouched', $base === layarkatalog_allow_font_origins_in_csp( $base ), json_encode( layarkatalog_allow_font_origins_in_csp( $base ) ) );
$GLOBALS['__theme_mods']['layarkatalog_google_font'] = 'Inter';
$out = layarkatalog_allow_font_origins_in_csp( $base );
t_assert( 'font selected: googleapis added to style-src', in_array( 'https://fonts.googleapis.com', $out['style-src'], true ), json_encode( $out['style-src'] ) );
t_assert( 'font selected: gstatic added to font-src', in_array( 'https://fonts.gstatic.com', $out['font-src'], true ), json_encode( $out['font-src'] ) );
t_assert( 'existing sources preserved', in_array( "'unsafe-inline'", $out['style-src'], true ) && in_array( 'data:', $out['font-src'], true ) );
t_assert( 'idempotent', 1 === count( array_keys( layarkatalog_allow_font_origins_in_csp( $out )['font-src'], 'https://fonts.gstatic.com', true ) ) );
t_assert( 'non-array input returned unchanged', 'nope' === layarkatalog_allow_font_origins_in_csp( 'nope' ) );

t_section( 'theme — advertising code audit' );
$locations  = layarkatalog_ad_locations();
t_assert( 'ad locations are defined', is_array( $locations ) && count( $locations ) > 0, (string) count( $locations ) );
$first_slot = array_key_first( $locations );

$before = count( $wpdb->inserts );
layarkatalog_audit_ad_code_change(
	array( 'slots' => array( $first_slot => array( 'code' => 'OLD CODE' ) ) ),
	array( 'slots' => array( $first_slot => array( 'code' => 'NEW CODE' ) ) )
);
t_assert( 'changed ad slot is audited', $before + 1 === count( $wpdb->inserts ), (string) ( count( $wpdb->inserts ) - $before ) );
$last   = end( $wpdb->inserts );
$ad_row = $last[1] ?? array();
t_assert( 'action name is ad_code_saved', 'ad_code_saved' === ( $ad_row['action'] ?? '' ), (string) ( $ad_row['action'] ?? '' ) );
t_assert( 'slot key is named', false !== strpos( (string) ( $ad_row['detail'] ?? '' ), (string) $first_slot ), (string) ( $ad_row['detail'] ?? '' ) );
t_assert( 'ad code itself is never stored', false === strpos( (string) ( $ad_row['detail'] ?? '' ), 'NEW CODE' ) && false === strpos( (string) ( $ad_row['detail'] ?? '' ), 'OLD CODE' ), (string) ( $ad_row['detail'] ?? '' ) );

$before = count( $wpdb->inserts );
$same   = array( 'slots' => array( $first_slot => array( 'code' => 'SAME' ) ) );
layarkatalog_audit_ad_code_change( $same, $same );
t_assert( 'unchanged settings are not audited', $before === count( $wpdb->inserts ) );

$before = count( $wpdb->inserts );
layarkatalog_audit_ad_code_change( 'layarkatalog_ads', array( 'global_head' => array( 'code' => '<script>ads()</script>' ) ) );
t_assert( 'add_option_ signature (name, value) handled', $before + 1 === count( $wpdb->inserts ), (string) ( count( $wpdb->inserts ) - $before ) );
$last    = end( $wpdb->inserts );
$ad_row2 = $last[1] ?? array();
t_assert( 'global head change recorded', false !== strpos( (string) ( $ad_row2['detail'] ?? '' ), 'global_head' ), (string) ( $ad_row2['detail'] ?? '' ) );
t_assert( 'script content never logged', false === strpos( (string) ( $ad_row2['detail'] ?? '' ), '<script>' ), (string) ( $ad_row2['detail'] ?? '' ) );

$before = count( $wpdb->inserts );
layarkatalog_audit_ad_code_change( array(), array() );
t_assert( 'empty options produce no entry', $before === count( $wpdb->inserts ) );

/* ================================================================== *
 * CSP strict mode: a nonce instead of 'unsafe-inline'
 * ================================================================== */
t_section( 'CSP — strict mode replaces unsafe-inline with a nonce' );

/* The hostile-filter section above leaves its payload registered; drop it so
   these assertions exercise the shipped defaults instead of the attacker's. */
unset( $GLOBALS['__filters']['lkc_content_security_policy'] );
unset( $GLOBALS['__filters']['lkc_csp_mode'] );
$GLOBALS['__user_id']    = 0;
$GLOBALS['__is_preview'] = false;

t_assert( 'loose mode is the default', false === lkc_is_csp_strict() );

$nonce = lkc_get_csp_nonce();
t_assert( 'a nonce is generated', '' !== $nonce, $nonce );
t_assert( 'the nonce uses the URL-safe base64 alphabet only', 1 === preg_match( '/^[A-Za-z0-9_-]{16,}$/', $nonce ), $nonce );
t_assert( 'the nonce is stable within one request', $nonce === lkc_get_csp_nonce() );
$GLOBALS['__filters']['lkc_csp_nonce'] = array(
	function () {
		return 'TOO-LATE';
	},
);
t_assert( 'a late nonce filter cannot desync the header from the markup', $nonce === lkc_get_csp_nonce() );
unset( $GLOBALS['__filters']['lkc_csp_nonce'] );

$loose = lkc_get_csp_directives();
t_assert( 'loose mode keeps unsafe-inline in script-src', in_array( "'unsafe-inline'", $loose['script-src'], true ), json_encode( $loose['script-src'] ) );
t_assert( 'loose mode sends no nonce', false === strpos( lkc_get_content_security_policy(), 'nonce-' ) );
t_assert( 'a guest response is not personalised in loose mode', false === lkc_is_personalized_response() );

$GLOBALS['__filters']['lkc_csp_strict'] = array(
	function () {
		return true;
	},
);

t_assert( 'strict mode can be switched on by filter', true === lkc_is_csp_strict() );
$strict = lkc_get_csp_directives();
t_assert( 'strict mode drops unsafe-inline from script-src', ! in_array( "'unsafe-inline'", $strict['script-src'], true ), json_encode( $strict['script-src'] ) );
t_assert( 'strict mode drops unsafe-inline from style-src', ! in_array( "'unsafe-inline'", $strict['style-src'], true ) );
t_assert( 'strict mode keeps self in script-src', in_array( "'self'", $strict['script-src'], true ) );
t_assert( 'strict mode adds the request nonce to script-src', in_array( "'nonce-" . $nonce . "'", $strict['script-src'], true ), json_encode( $strict['script-src'] ) );
t_assert( 'strict mode adds the request nonce to style-src', in_array( "'nonce-" . $nonce . "'", $strict['style-src'], true ) );
t_assert( 'the nonce appears exactly once per directive', 1 === count( array_keys( $strict['script-src'], "'nonce-" . $nonce . "'", true ) ) );
t_assert( 'the header carries that same nonce', false !== strpos( lkc_get_content_security_policy(), "'nonce-" . $nonce . "'" ), lkc_get_content_security_policy() );
t_assert( 'unrelated directives are untouched', array( "'self'", 'data:', 'https:' ) === $strict['img-src'], json_encode( $strict['img-src'] ) );

$report_only = lkc_get_content_security_policy_header();
t_assert( 'report-only is still the default delivery mode', isset( $report_only['Content-Security-Policy-Report-Only'] ) && ! isset( $report_only['Content-Security-Policy'] ), json_encode( array_keys( $report_only ) ) );

/* A filter must not be able to put 'unsafe-inline' back once strict mode is on. */
$GLOBALS['__filters']['lkc_content_security_policy'] = array(
	function ( $policy ) {
		$policy['script-src'][] = "'unsafe-inline'";
		$policy['script-src'][] = "'unsafe-eval'";
		return $policy;
	},
);
$forced = lkc_get_csp_directives();
t_assert( 'a filter cannot reintroduce unsafe-inline in strict mode', ! in_array( "'unsafe-inline'", $forced['script-src'], true ), json_encode( $forced['script-src'] ) );
t_assert( 'a filter cannot reintroduce unsafe-eval in strict mode', ! in_array( "'unsafe-eval'", $forced['script-src'], true ) );
t_assert( 'the nonce survives that filter', in_array( "'nonce-" . $nonce . "'", $forced['script-src'], true ) );
unset( $GLOBALS['__filters']['lkc_content_security_policy'] );

t_assert( 'strict mode never invents a missing directive', ! isset( lkc_apply_csp_strict_mode( array( 'default-src' => array( "'self'" ) ) )['script-src'] ) );
t_assert( 'strict mode strips unsafe-inline from default-src too', ! in_array( "'unsafe-inline'", lkc_apply_csp_strict_mode( array( 'default-src' => array( "'self'", "'unsafe-inline'" ) ) )['default-src'], true ) );
t_assert( 'a non-array policy is normalised', array() === lkc_apply_csp_strict_mode( 'nope' ) );

t_assert( 'inline script elements get the nonce', $nonce === lkc_stamp_csp_nonce_on_element( array( 'id' => 'lkc-js-extra' ) )['nonce'] );
t_assert( 'inline style elements get the nonce', $nonce === lkc_stamp_csp_nonce_on_element( array( 'id' => 'global-styles-inline-css' ) )['nonce'] );
t_assert( 'external scripts are left alone', ! isset( lkc_stamp_csp_nonce_on_element( array( 'src' => 'https://example.test/a.js' ) )['nonce'] ) );
t_assert( 'external stylesheets are left alone', ! isset( lkc_stamp_csp_nonce_on_element( array( 'href' => 'https://example.test/a.css' ) )['nonce'] ) );
t_assert( 'an existing nonce is not overwritten', 'kept' === lkc_stamp_csp_nonce_on_element( array( 'nonce' => 'kept' ) )['nonce'] );
t_assert( 'the other attributes survive stamping', 'lkc-js-extra' === lkc_stamp_csp_nonce_on_element( array( 'id' => 'lkc-js-extra' ) )['id'] );
t_assert( 'non-array attributes are returned unchanged', 'nope' === lkc_stamp_csp_nonce_on_element( 'nope' ) );

t_assert( 'nonce-bearing HTML is marked personalised', true === lkc_is_personalized_response() );

unset( $GLOBALS['__filters']['lkc_csp_strict'] );
t_assert( 'loose mode is restored', false === lkc_is_csp_strict() );
t_assert( 'elements are no longer stamped', ! isset( lkc_stamp_csp_nonce_on_element( array( 'id' => 'x' ) )['nonce'] ) );
t_assert( 'a guest response is not personalised again', false === lkc_is_personalized_response() );
t_assert( 'unsafe-inline is back in the loose policy', in_array( "'unsafe-inline'", lkc_get_csp_directives()['script-src'], true ) );

/* ================================================================== *
 * Theme: stamping a nonce on raw advertising markup
 * ================================================================== */
t_section( 'theme — CSP nonce on raw advertising markup' );

$raw     = '<script async src="https://ads.test/a.js"></script><script>alert(1)</script><style>.a{color:red}</style><script type="text/javascript">var b=2;</script><SCRIPT nonce="keep">c</SCRIPT>';
$stamped = layarkatalog_add_csp_nonce_to_inline_tags( $raw, 'ABC-123_x' );
t_assert( 'an inline script receives the nonce', false !== strpos( $stamped, '<script nonce="ABC-123_x">alert(1)</script>' ), $stamped );
t_assert( 'an external ad loader is left alone', false !== strpos( $stamped, '<script async src="https://ads.test/a.js">' ), $stamped );
t_assert( 'an inline style receives the nonce', false !== strpos( $stamped, '<style nonce="ABC-123_x">.a{color:red}</style>' ) );
t_assert( 'existing attributes are preserved', false !== strpos( $stamped, '<script nonce="ABC-123_x" type="text/javascript">var b=2;</script>' ) );
t_assert( 'a tag that already has a nonce keeps it', 1 === substr_count( $stamped, 'nonce="keep"' ) && false === strpos( $stamped, '<SCRIPT nonce="ABC-123_x"' ), $stamped );
t_assert( 'the script payload itself is not modified', false !== strpos( $stamped, '>alert(1)</script>' ) );
t_assert( 'uppercase tag names are matched', false !== strpos( $stamped, '<SCRIPT nonce="keep">c</SCRIPT>' ) );
t_assert( 'illegal nonce characters are stripped', false !== strpos( layarkatalog_add_csp_nonce_to_inline_tags( '<script>x</script>', 'a"b<c d' ), 'nonce="abcd"' ) );
t_assert( 'an empty nonce leaves the markup alone', $raw === layarkatalog_add_csp_nonce_to_inline_tags( $raw, '' ) );
t_assert( 'markup without inline tags is unchanged', '<p>hi</p>' === layarkatalog_add_csp_nonce_to_inline_tags( '<p>hi</p>', 'ABC-123_x' ) );
t_assert( 'empty markup stays empty', '' === layarkatalog_add_csp_nonce_to_inline_tags( '', 'ABC-123_x' ) );

t_assert( 'the wrapper is a no-op in loose mode', $raw === layarkatalog_stamp_csp_nonce( $raw ) );
$GLOBALS['__filters']['lkc_csp_strict'] = array(
	function () {
		return true;
	},
);
t_assert( 'the wrapper uses the request nonce in strict mode', false !== strpos( layarkatalog_stamp_csp_nonce( '<script>x</script>' ), 'nonce="' . $nonce . '"' ), layarkatalog_stamp_csp_nonce( '<script>x</script>' ) );
unset( $GLOBALS['__filters']['lkc_csp_strict'] );

/* ================================================================== *
 * CSP reports turned into an allowlist snippet
 * ================================================================== */
t_section( 'CSP — reported directive folding' );
t_assert( 'script-src-elem folds into script-src', 'script-src' === lkc_csp_base_directive( 'script-src-elem' ) );
t_assert( 'style-src-attr folds into style-src', 'style-src' === lkc_csp_base_directive( 'style-src-attr' ) );
t_assert( 'script-src-inline folds into script-src', 'script-src' === lkc_csp_base_directive( 'SCRIPT-SRC-INLINE' ) );
t_assert( 'a known directive passes through', 'connect-src' === lkc_csp_base_directive( ' connect-src ' ) );
t_assert( 'frame-ancestors is known', 'frame-ancestors' === lkc_csp_base_directive( 'frame-ancestors' ) );
t_assert( 'an unknown directive is rejected', '' === lkc_csp_base_directive( 'x-evil-src' ) );
t_assert( 'a second directive smuggled into the name is rejected', '' === lkc_csp_base_directive( 'default-src; frame-ancestors' ) );
t_assert( 'an empty directive is rejected', '' === lkc_csp_base_directive( '' ) );

t_section( 'CSP — host validation' );
t_assert( 'a registrable host is accepted', true === lkc_is_csp_allowlist_host( 'pagead2.googlesyndication.com' ) );
t_assert( 'a hyphenated host is accepted', true === lkc_is_csp_allowlist_host( 'cdn-1.example.test' ) );
t_assert( 'an IPv4 literal is accepted', true === lkc_is_csp_allowlist_host( '203.0.113.7' ) );
t_assert( 'a single label is rejected', false === lkc_is_csp_allowlist_host( 'localhost' ) );
t_assert( 'the inline keyword is rejected', false === lkc_is_csp_allowlist_host( 'inline' ) );
t_assert( 'the eval keyword is rejected', false === lkc_is_csp_allowlist_host( 'eval' ) );
t_assert( 'a scheme is rejected', false === lkc_is_csp_allowlist_host( 'https://example.test' ) );
t_assert( 'a path is rejected', false === lkc_is_csp_allowlist_host( 'example.test/a.js' ) );
t_assert( 'a port is rejected', false === lkc_is_csp_allowlist_host( 'example.test:8080' ) );
t_assert( 'an empty value is rejected', false === lkc_is_csp_allowlist_host( '' ) );
t_assert( 'an over-long value is rejected', false === lkc_is_csp_allowlist_host( str_repeat( 'a.', 100 ) . 'test' ) );

t_section( 'CSP — sources already allowed' );
t_assert( 'an exact origin is already allowed', true === lkc_csp_source_already_allows( array( 'https://cdn.test' ), 'cdn.test' ) );
t_assert( 'a scheme-relative origin is already allowed', true === lkc_csp_source_already_allows( array( '//cdn.test' ), 'cdn.test' ) );
t_assert( 'the https: wildcard covers any https host', true === lkc_csp_source_already_allows( array( "'self'", 'https:' ), 'cdn.test' ) );
t_assert( 'the * wildcard covers everything', true === lkc_csp_source_already_allows( array( '*' ), 'cdn.test' ) );
t_assert( 'a wildcard subdomain covers a subdomain', true === lkc_csp_source_already_allows( array( 'https://*.example.test' ), 'cdn.example.test' ) );
t_assert( 'a wildcard subdomain covers the apex', true === lkc_csp_source_already_allows( array( 'https://*.example.test' ), 'example.test' ) );
t_assert( 'an unrelated host is not covered', false === lkc_csp_source_already_allows( array( 'https://cdn.test' ), 'evil.test' ) );
t_assert( 'a prefix lookalike is not covered', false === lkc_csp_source_already_allows( array( 'https://*.example.test' ), 'notexample.test' ) );
t_assert( 'a suffix lookalike is not covered', false === lkc_csp_source_already_allows( array( 'https://cdn.test' ), 'xcdn.test' ) );
t_assert( 'an empty source list covers nothing', false === lkc_csp_source_already_allows( array(), 'cdn.test' ) );

t_section( 'CSP — report grouping' );
$rows    = array(
	array( 'directive' => 'script-src-elem', 'blocked_host' => 'pagead2.googlesyndication.com', 'violations' => 120, 'first_seen' => '2026-10-01 08:00:00', 'last_seen' => '2026-10-17 08:00:00' ),
	array( 'directive' => 'script-src', 'blocked_host' => 'pagead2.googlesyndication.com', 'violations' => 4, 'first_seen' => '2026-09-30 08:00:00', 'last_seen' => '2026-10-16 08:00:00' ),
	array( 'directive' => 'connect-src', 'blocked_host' => 'stats.example.test', 'violations' => 7, 'first_seen' => '2026-10-02 08:00:00', 'last_seen' => '2026-10-03 08:00:00' ),
	array( 'directive' => 'img-src', 'blocked_host' => 'already.example.test', 'violations' => 3, 'first_seen' => '2026-10-02 08:00:00', 'last_seen' => '2026-10-03 08:00:00' ),
	array( 'directive' => 'script-src', 'blocked_host' => 'inline', 'violations' => 55, 'first_seen' => '2026-10-02 08:00:00', 'last_seen' => '2026-10-03 08:00:00' ),
	array( 'directive' => 'font-src', 'blocked_host' => 'data', 'violations' => 2, 'first_seen' => '2026-10-02 08:00:00', 'last_seen' => '2026-10-03 08:00:00' ),
	array( 'directive' => 'script-src', 'blocked_host' => 'example.test', 'violations' => 9, 'first_seen' => '2026-10-02 08:00:00', 'last_seen' => '2026-10-03 08:00:00' ),
	array( 'directive' => 'bogus-src', 'blocked_host' => 'whatever.test', 'violations' => 9, 'first_seen' => '2026-10-02 08:00:00', 'last_seen' => '2026-10-03 08:00:00' ),
	array( 'directive' => 'style-src', 'blocked_host' => '', 'violations' => 1, 'first_seen' => '', 'last_seen' => '' ),
);
$grouped = lkc_group_csp_reports( $rows );
t_assert( 'an -elem variant is folded into its base directive', 124 === $grouped['allowlist']['script-src']['pagead2.googlesyndication.com'], json_encode( $grouped['allowlist'] ) );
t_assert( 'keywords are separated from hosts', 55 === $grouped['keywords']['script-src']['inline'] );
t_assert( 'an empty blocked_host becomes "unknown"', 1 === $grouped['keywords']['style-src']['unknown'] );
t_assert( 'rows with an unknown directive are dropped', ! isset( $grouped['allowlist']['bogus-src'] ) && false === strpos( json_encode( $grouped ), 'whatever.test' ) );
t_assert( 'the window spans the earliest and latest sighting', '2026-09-30 08:00:00' === $grouped['first_seen'] && '2026-10-17 08:00:00' === $grouped['last_seen'], $grouped['first_seen'] . ' .. ' . $grouped['last_seen'] );
t_assert( 'empty input produces empty groups', array() === lkc_group_csp_reports( array() )['allowlist'] && array() === lkc_group_csp_reports( array() )['keywords'] );
t_assert( 'stdClass rows are accepted too', 5 === lkc_group_csp_reports( array( (object) array( 'directive' => 'media-src', 'blocked_host' => 'video.test', 'violations' => 5 ) ) )['allowlist']['media-src']['video.test'] );

t_section( 'CSP — generated allowlist snippet' );
$current = array(
	'default-src' => array( "'self'" ),
	'script-src'  => array( "'self'", "'unsafe-inline'" ),
	'style-src'   => array( "'self'", "'unsafe-inline'" ),
	'img-src'     => array( "'self'", 'data:', 'https:' ),
	'connect-src' => array( "'self'" ),
	'font-src'    => array( "'self'", 'data:' ),
);
$snippet = lkc_build_csp_allowlist_snippet( $grouped, $current );
t_assert( 'the snippet is a filter registration', 0 === strpos( $snippet, 'add_filter( \'lkc_content_security_policy\', function ( $policy ) {' ), $snippet );
t_assert( 'the discovered host is added to the right directive', false !== strpos( $snippet, "\$policy['script-src'][] = 'https://pagead2.googlesyndication.com'; // 124 pelanggaran" ), $snippet );
t_assert( 'a second directive is added too', false !== strpos( $snippet, "\$policy['connect-src'][] = 'https://stats.example.test'; // 7 pelanggaran" ) );
t_assert( 'a host already covered by https: is skipped', false === strpos( $snippet, 'already.example.test' ), $snippet );
t_assert( 'the site itself is explained, not allowlisted', false === strpos( $snippet, "\$policy['script-src'][] = 'https://example.test'" ) && false !== strpos( $snippet, 'adalah situs ini sendiri' ) );
t_assert( 'the inline keyword becomes advice about nonces', false === strpos( $snippet, 'https://inline' ) && false !== strpos( $snippet, 'lkc_csp_strict' ) );
t_assert( 'the data keyword becomes advice about the scheme', false !== strpos( $snippet, "'data' pada font-src" ) );
t_assert( 'the collection window is named', false !== strpos( $snippet, 'antara 2026-09-30 dan 2026-10-17' ) );
t_assert( 'the callback is closed', '} );' === substr( $snippet, -4 ) );
t_assert( 'the snippet contains no closing PHP tag', false === strpos( $snippet, '?>' ) );
t_assert( 'an empty report set still explains itself', false !== strpos( lkc_build_csp_allowlist_snippet( array(), array() ), 'Tidak ada domain baru yang perlu diizinkan' ) );
t_assert( 'already-allowed sources are counted in that explanation', false !== strpos( lkc_build_csp_allowlist_snippet( lkc_group_csp_reports( array( array( 'directive' => 'img-src', 'blocked_host' => 'x.test', 'violations' => 2 ) ) ), array( 'img-src' => array( 'https:' ) ) ), 'sumber terlapor sudah diizinkan' ) );

/* The snippet has to be valid PHP that actually does what it claims. */
$saved_filters = isset( $GLOBALS['__filters']['lkc_content_security_policy'] ) ? $GLOBALS['__filters']['lkc_content_security_policy'] : array();
eval( $snippet ); // phpcs:ignore Squiz.PHP.Eval -- proving the generated snippet parses and behaves.
$generated                                          = $GLOBALS['__filters']['lkc_content_security_policy'];
$GLOBALS['__filters']['lkc_content_security_policy'] = $saved_filters;
t_assert( 'the generated snippet parses and registers exactly one filter', 1 === count( $generated ) );
$applied = $generated[0]( array( 'script-src' => array( "'self'" ), 'connect-src' => array( "'self'" ) ) );
t_assert( 'the generated filter adds the discovered hosts', in_array( 'https://pagead2.googlesyndication.com', $applied['script-src'], true ), json_encode( $applied ) );
t_assert( 'the generated filter keeps the existing sources', in_array( "'self'", $applied['script-src'], true ) && in_array( "'self'", $applied['connect-src'], true ) );
t_assert( 'the generated filter covers every directive it listed', in_array( 'https://stats.example.test', $applied['connect-src'], true ) );
$twice = $generated[0]( $applied );
t_assert( 'a duplicated source still renders one directive', 1 === substr_count( lkc_stringify_csp_directives( $twice ), 'script-src' ), lkc_stringify_csp_directives( $twice ) );

/* ================================================================== */
echo "\n=================================\n";
echo $GLOBALS['lkc_test_pass'] . ' passed, ' . $GLOBALS['lkc_test_fail'] . " failed\n";

$exit_code = $GLOBALS['lkc_test_fail'] > 0 ? 1 : 0;
if ( defined( 'LKC_TEST_NO_EXIT' ) ) {
	/* The WebAssembly runner cannot call exit() without aborting the stream. */
	echo "TEST_EXIT_CODE={$exit_code}\n";
} else {
	exit( $exit_code );
}
