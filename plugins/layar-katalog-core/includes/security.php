<?php
/**
 * Baseline HTTP security headers, CORS policy, and REST request limits.
 *
 * @package Layar_Katalog_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduce a URL to its CORS origin: scheme, host, and port only.
 *
 * @param string $url Absolute URL. A value without a scheme is rejected rather
 *                    than guessed at, so an allowlist entry can never widen by accident.
 * @return string Normalised origin, or an empty string when unusable.
 */
function lkc_normalize_origin( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
	if ( ! empty( $parts['port'] ) ) {
		$port = absint( $parts['port'] );
		/* Browsers omit the default port in an Origin header, so canonicalise both sides. */
		$is_default = ( 'https' === strtolower( $parts['scheme'] ) && 443 === $port ) || ( 'http' === strtolower( $parts['scheme'] ) && 80 === $port );
		if ( ! $is_default ) {
			$origin .= ':' . $port;
		}
	}
	return $origin;
}

/**
 * Build the Content-Security-Policy for front-end responses.
 *
 * @return string Policy header value, or an empty string when nothing is left.
 */
function lkc_get_csp_directives() {
	$report_uri = esc_url_raw( rest_url( 'lkc/v1/csp-report' ) );

	$policy = array(
		'default-src'     => array( "'self'" ),
		/*
		 * 'unsafe-inline' is needed by default because the companion theme prints
		 * administrator-supplied advertising code into wp_head, WordPress core
		 * prints inline global styles and localised script data, and block markup
		 * can carry inline style attributes. Return true from `lkc_csp_strict` to
		 * drop it in favour of a per-request nonce.
		 */
		'script-src'      => array( "'self'", "'unsafe-inline'" ),
		'style-src'       => array( "'self'", "'unsafe-inline'" ),
		'img-src'         => array( "'self'", 'data:', 'https:' ),
		/* Direct MP4/WebM sources and object URLs used by the player. */
		'media-src'       => array( "'self'", 'https:', 'blob:' ),
		'font-src'        => array( "'self'", 'data:' ),
		'connect-src'     => array( "'self'" ),
		'frame-src'       => array( "'self'" ),
		'object-src'      => array( "'none'" ),
		'base-uri'        => array( "'self'" ),
		'form-action'     => array( "'self'" ),
		'frame-ancestors' => array( "'self'" ),
	);

	if ( $report_uri ) {
		$policy['report-uri'] = array( $report_uri );
	}

	/**
	 * Filter the Content-Security-Policy directives.
	 *
	 * Each entry maps a directive name to a list of source expressions. Add the
	 * domains of your advertising, analytics, font, and video providers here, for
	 * example:
	 *
	 *     add_filter( 'lkc_content_security_policy', function ( $policy ) {
	 *         $policy['script-src'][] = 'https://pagead2.googlesyndication.com';
	 *         $policy['connect-src'][] = 'https://cdn.example.com';
	 *         return $policy;
	 *     } );
	 *
	 * Run the site in report-only mode first and read Tools → Log Keamanan to
	 * discover which sources are actually being blocked.
	 *
	 * @param array $policy Directive name => array of source expressions.
	 */
	$policy = apply_filters( 'lkc_content_security_policy', $policy );

	/* Applied after user filters so that 'unsafe-inline' cannot be reintroduced in strict mode. */
	$policy = lkc_apply_csp_strict_mode( $policy );

	/**
	 * Filter the final Content-Security-Policy directive list.
	 *
	 * Runs after `lkc_content_security_policy` and after strict mode has been
	 * applied, so this is the last chance to inspect what will actually be sent.
	 *
	 * @param array $policy Directive name => array of source expressions.
	 */
	$policy = (array) apply_filters( 'lkc_csp_directives', $policy );

	return $policy;
}

/**
 * Render a directive list as a Content-Security-Policy header value.
 *
 * Directive names must be a single token and source expressions may not contain
 * a semicolon or a newline, because either would start a second directive.
 * Anything that does not comply is dropped rather than rewritten.
 *
 * @param array $policy Directive name => array of source expressions.
 * @return string
 */
function lkc_stringify_csp_directives( $policy ) {
	$parts = array();
	foreach ( (array) $policy as $directive => $sources ) {
		$raw_directive = trim( (string) $directive );
		/* A directive name is a single token: reject anything else instead of rewriting it. */
		if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9-]*$/', $raw_directive ) ) {
			continue;
		}
		$directive = sanitize_key( $raw_directive );
		$clean = array();
		foreach ( (array) $sources as $source ) {
			$raw = (string) $source;
			/* A semicolon or newline would start another directive: never trust a filter with that. */
			if ( false !== strpos( $raw, ';' ) || false !== strpbrk( $raw, "\r\n" ) ) {
				continue;
			}
			$raw = trim( $raw );
			if ( '' === $raw ) {
				continue;
			}
			$clean[] = $raw;
		}
		if ( $clean ) {
			$parts[] = $directive . ' ' . implode( ' ', $clean );
		}
	}

	return $parts ? implode( '; ', $parts ) : '';
}

/**
 * Content-Security-Policy value for the current request.
 *
 * @return string Empty string when the policy would be empty.
 */
function lkc_get_content_security_policy() {
	return lkc_stringify_csp_directives( lkc_get_csp_directives() );
}

/**
 * Content-Security-Policy header pair for the current mode.
 *
 * @return array Header name => value, or an empty array when CSP is off.
 */
function lkc_get_content_security_policy_header() {
	/**
	 * Filter how the Content-Security-Policy is delivered.
	 *
	 * 'report-only' (default) sends Content-Security-Policy-Report-Only, which
	 * reports violations without blocking anything. 'enforce' blocks them.
	 * 'off' sends neither.
	 *
	 * @param string $mode One of 'report-only', 'enforce', 'off'.
	 */
	$mode = (string) apply_filters( 'lkc_csp_mode', 'report-only' );
	if ( 'off' === $mode ) {
		return array();
	}

	$policy = lkc_get_content_security_policy();
	if ( ! $policy ) {
		return array();
	}

	$name = ( 'enforce' === $mode ) ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

	return array( $name => $policy );
}

/**
 * Whether the Content-Security-Policy runs without 'unsafe-inline'.
 *
 * Off by default. Turning it on replaces `'unsafe-inline'` in `script-src` and
 * `style-src` with a per-request nonce, which is the only mechanism that also
 * covers the inline `<style>` and `<script>` elements WordPress core prints
 * itself (global styles, emoji settings, localised script data).
 *
 * @return bool
 */
function lkc_is_csp_strict() {
	/**
	 * Filter whether the Content-Security-Policy drops 'unsafe-inline'.
	 *
	 * Strict mode is only safe once every source of inline markup on the site is
	 * accounted for: the bundled theme stamps a nonce on its own inline output and
	 * on administrator-supplied advertising code, but a third-party plugin that
	 * echoes a raw `<script>` tag will be blocked. Test in report-only mode
	 * (`lkc_csp_mode`) first and read Tools → Log Keamanan before enforcing.
	 *
	 * Strict mode also marks every front-end response as personalised, because a
	 * page cache serving one visitor's nonce to the next would break their
	 * scripts and styles. See `lkc_is_personalized_response`.
	 *
	 *     add_filter( 'lkc_csp_strict', '__return_true' );
	 *
	 * @param bool $strict Whether to drop 'unsafe-inline'. Default false.
	 */
	return (bool) apply_filters( 'lkc_csp_strict', false );
}

/**
 * Per-request nonce used by the strict Content-Security-Policy.
 *
 * Generated once per request so that the header and every inline element carry
 * the same value. Sites that must keep full-page caching can supply a stable
 * nonce of their own through the `lkc_csp_nonce` filter, for example one derived
 * from a cookie the cache is configured to vary on.
 *
 * @return string Empty string when no usable nonce could be produced.
 */
function lkc_get_csp_nonce() {
	static $nonce = null;

	if ( null !== $nonce ) {
		return $nonce;
	}

	$generated = '';
	if ( function_exists( 'random_bytes' ) ) {
		try {
			$generated = base64_encode( random_bytes( 18 ) );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$generated = '';
		}
	}
	if ( '' === $generated ) {
		$generated = base64_encode( (string) wp_generate_password( 18, false, false ) );
	}

	/* Nonce values are compared literally by the browser, so keep the URL-safe alphabet only. */
	$generated = rtrim( strtr( $generated, '+/', '-_' ), '=' );

	/**
	 * Filter the Content-Security-Policy nonce for this request.
	 *
	 * Only useful for sites that cache full HTML pages and need the same nonce
	 * across cache hits. Return an empty string to keep the generated one.
	 *
	 * @param string $nonce Generated nonce, URL-safe base64.
	 */
	$filtered = (string) apply_filters( 'lkc_csp_nonce', $generated );
	$filtered = preg_replace( '/[^A-Za-z0-9_-]/', '', $filtered );

	$nonce = ( '' !== $filtered ) ? $filtered : $generated;

	return $nonce;
}

/**
 * Drop 'unsafe-inline'/'unsafe-eval' and add the request nonce when strict.
 *
 * The nonce is only added to directives that already exist: inventing a
 * `script-src` holding nothing but a nonce would block every external script,
 * which is stricter than the site asked for.
 *
 * @param array $policy Directive name => array of source expressions.
 * @return array
 */
function lkc_apply_csp_strict_mode( $policy ) {
	if ( ! is_array( $policy ) || ! lkc_is_csp_strict() ) {
		return is_array( $policy ) ? $policy : array();
	}

	foreach ( $policy as $directive => $sources ) {
		if ( ! is_array( $sources ) ) {
			unset( $policy[ $directive ] );
			continue;
		}
		$policy[ $directive ] = array_values(
			array_filter(
				$sources,
				static function ( $source ) {
					return ! in_array( (string) $source, array( "'unsafe-inline'", "'unsafe-eval'" ), true );
				}
			)
		);
	}

	$nonce = lkc_get_csp_nonce();
	if ( '' === $nonce ) {
		return $policy;
	}

	$keyword = "'nonce-" . $nonce . "'";
	foreach ( array( 'script-src', 'style-src' ) as $directive ) {
		if ( ! isset( $policy[ $directive ] ) || ! is_array( $policy[ $directive ] ) ) {
			continue;
		}
		if ( ! in_array( $keyword, $policy[ $directive ], true ) ) {
			$policy[ $directive ][] = $keyword;
		}
	}

	/*
	 * Style attributes are the one thing a nonce cannot cover, and WordPress
	 * prints them everywhere: a navigation blockGap, a column width, a cover
	 * overlay, a duotone filter. Dropping 'unsafe-inline' from style-src without
	 * saying so here would strip the layout off pages that otherwise keep working,
	 * which is read as "strict mode broke my site" rather than as a policy. So
	 * style-src-attr keeps the keyword while script-src-attr stays strict, which
	 * means an inline on* handler is still blocked. See
	 * lkc_csp_strict_allows_style_attributes() for turning this off.
	 */
	if ( lkc_csp_strict_allows_style_attributes() ) {
		$attributes = isset( $policy['style-src-attr'] ) && is_array( $policy['style-src-attr'] ) ? $policy['style-src-attr'] : array();
		if ( ! in_array( "'unsafe-inline'", $attributes, true ) ) {
			$attributes[] = "'unsafe-inline'";
		}
		$policy['style-src-attr'] = $attributes;
	}

	return $policy;
}

/**
 * Whether strict mode still permits inline style attributes.
 *
 * True by default, and the concession is deliberately narrow: it applies to
 * `style-src-attr` only, so inline <style> elements still need the nonce and
 * `script-src-attr` still blocks inline event handlers. The bundled theme and
 * plugin print no style attributes of their own any more - the ad slot reserves
 * its height through a nonce'd <style> element and the header navigation gap
 * lives in style.css - so a site that only uses them can turn this off:
 *
 *     add_filter( 'lkc_csp_strict_style_attributes', '__return_false' );
 *
 * Turn it off only after watching Tools → Log Keamanan for `style-attribute`
 * reports, because core blocks and third-party plugins are the usual source and
 * they are not visible in the theme's own markup.
 *
 * @return bool
 */
function lkc_csp_strict_allows_style_attributes() {
	/**
	 * Filter whether strict mode keeps 'unsafe-inline' for style attributes.
	 *
	 * @param bool $allow True to keep inline style attributes working. Default true.
	 */
	return (bool) apply_filters( 'lkc_csp_strict_style_attributes', true );
}

/**
 * Add the CSP nonce to inline script and style elements WordPress prints.
 *
 * Hooked to `wp_script_attributes` and `wp_style_attributes` so that core's own
 * inline output — global styles, emoji settings, localised script data — is
 * covered without each plugin having to know about the policy. Elements with a
 * `src` or `href` are left alone: a nonce on an external resource is ignored by
 * browsers and only adds noise to the markup.
 *
 * @param array $attributes Element attributes about to be printed.
 * @return array
 */
function lkc_stamp_csp_nonce_on_element( $attributes ) {
	if ( ! is_array( $attributes ) || ! lkc_is_csp_strict() ) {
		return $attributes;
	}
	if ( ! empty( $attributes['src'] ) || ! empty( $attributes['href'] ) || ! empty( $attributes['nonce'] ) ) {
		return $attributes;
	}

	$nonce = lkc_get_csp_nonce();
	if ( '' !== $nonce ) {
		$attributes['nonce'] = $nonce;
	}

	return $attributes;
}
add_filter( 'wp_script_attributes', 'lkc_stamp_csp_nonce_on_element' );
add_filter( 'wp_style_attributes', 'lkc_stamp_csp_nonce_on_element' );

/**
 * Whether the HTML about to be sent is specific to this visitor.
 *
 * The bundled service worker refuses to store such responses, which keeps a
 * signed-in header or a post preview from being served to the next person on
 * the same device.
 *
 * @return bool
 */
function lkc_is_personalized_response() {
	if ( is_user_logged_in() || is_preview() ) {
		return true;
	}

	/*
	 * A strict Content-Security-Policy carries a per-request nonce, so the HTML
	 * itself becomes visitor-specific: serving it from a cache to somebody else
	 * would break their scripts and styles.
	 */
	return lkc_is_csp_strict() && '' !== lkc_get_csp_nonce();
}

/**
 * Send baseline security headers on front-end responses.
 *
 * WordPress core only sets X-Frame-Options for the login and admin screens, so
 * without this the public site ships with no security headers at all.
 *
 * The Content-Security-Policy is sent in report-only mode by default so that a
 * site using third-party advertising code keeps working while the real violation
 * list is collected. See `lkc_csp_mode`.
 */
function lkc_send_security_headers() {
	if ( headers_sent() || is_admin() ) {
		return;
	}

	$serving_https = function_exists( 'wp_is_serving_https' ) ? wp_is_serving_https() : is_ssl();

	$headers = array(
		/* Never guess a response type; blocks drive-by script MIME confusion. */
		'X-Content-Type-Options' => 'nosniff',
		/* Keep the URL out of third-party requests, but allow it same-origin. */
		'Referrer-Policy'        => 'strict-origin-when-cross-origin',
		/* Block clickjacking of catalog pages in a foreign frame. */
		'X-Frame-Options'        => 'SAMEORIGIN',
		/* The site uses none of these; declaring them stops silent permission grants. */
		'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()',
	);

	if ( $serving_https ) {
		$headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
	}

	/* Report-only by default so third-party ad code keeps working while violations are collected. */
	$headers = array_merge( $headers, lkc_get_content_security_policy_header() );

	/**
	 * Filter the security headers sent on front-end responses.
	 *
	 * Return an empty array to disable them entirely. Sites that share a parent
	 * domain with non-HTTPS services should drop `includeSubDomains` from the
	 * HSTS value, and sites whose admin area lives on another host than the
	 * front end should drop X-Frame-Options so the Customizer preview works.
	 *
	 * @param array $headers Header name => header value.
	 */
	$headers = apply_filters( 'lkc_security_headers', $headers );

	foreach ( (array) $headers as $name => $value ) {
		if ( is_string( $name ) && is_string( $value ) && '' !== trim( $value ) ) {
			header( $name . ': ' . $value );
		}
	}

	if ( lkc_is_personalized_response() ) {
		/*
		 * Tells the bundled service worker that this HTML is personalised, so it
		 * is never written to Cache Storage and cannot be served to the next
		 * person who uses the same device. Sent outside the filter above because
		 * it is a correctness signal, not a policy choice.
		 */
		header( 'X-LK-Personalized: 1' );
	}
}
add_action( 'send_headers', 'lkc_send_security_headers' );

/**
 * Replace WordPress's reflected CORS headers on the plugin's own routes.
 *
 * Core's `rest_send_cors_headers()` echoes any Origin back as
 * `Access-Control-Allow-Origin` together with `Access-Control-Allow-Credentials:
 * true`, which lets any website read credentialed responses from this namespace.
 * Only origins on the allowlist keep those headers; everything else gets none,
 * so cross-origin JavaScript cannot read the response at all.
 *
 * @param WP_REST_Request $request Request being served.
 */
function lkc_restrict_cors_for_route( $request ) {
	if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || headers_sent() ) {
		return;
	}

	$route = (string) $request->get_route();
	if ( ! str_starts_with( $route, '/lkc/v1' ) ) {
		return;
	}

	$request_origin = lkc_normalize_origin( (string) get_http_origin() );

	/* Always vary on Origin so a shared cache cannot serve one origin's headers to another. */
	header( 'Vary: Origin', false );

	if ( $request_origin && lkc_is_allowed_origin( $request_origin ) ) {
		header( 'Access-Control-Allow-Origin: ' . $request_origin );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		return;
	}

	header_remove( 'Access-Control-Allow-Origin' );
	header_remove( 'Access-Control-Allow-Credentials' );
	header_remove( 'Access-Control-Allow-Methods' );
}

/**
 * Apply the CORS policy after core has sent its own headers.
 *
 * Core attaches its CORS handling to either `rest_pre_serve_request` or
 * `rest_post_dispatch` depending on the WordPress version, so the policy is
 * applied on both at a later priority. Removing a header that was never sent is
 * harmless, which makes running twice safe.
 *
 * @param mixed           $result  Value being filtered.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request being served.
 * @return mixed Unchanged first argument.
 */
function lkc_filter_rest_cors_headers( $result, $server, $request ) {
	lkc_restrict_cors_for_route( $request );
	return $result;
}
add_filter( 'rest_pre_serve_request', 'lkc_filter_rest_cors_headers', 20, 3 );
add_filter( 'rest_post_dispatch', 'lkc_filter_rest_cors_headers', 20, 3 );

/**
 * Client address used for throttling and audit hashing.
 *
 * @return string
 */
function lkc_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';

	/**
	 * Filter the client address used for rate limiting and audit hashing.
	 *
	 * Sites behind a trusted reverse proxy or CDN must return the real client
	 * address here (for example from CF-Connecting-IP or a single trusted
	 * X-Forwarded-For hop), because REMOTE_ADDR is the proxy address for every
	 * visitor and would collapse the whole audience into one bucket. Only trust a
	 * header you know your infrastructure sets.
	 *
	 * @param string $ip Raw REMOTE_ADDR value.
	 */
	return (string) apply_filters( 'lkc_rate_limit_ip', $ip );
}

/**
 * Build a stable, privacy-preserving identifier for the current visitor.
 *
 * Signed-in users are throttled per account. Anonymous visitors are throttled
 * per IP address, but the address is hashed with the site's auth salt first so
 * the raw value is never written to the options table that backs transients.
 *
 * @return string
 */
function lkc_get_rate_limit_identity() {
	$user_id = get_current_user_id();
	if ( $user_id ) {
		return 'u' . $user_id;
	}

	return 'ip' . substr( hash_hmac( 'sha256', lkc_client_ip(), wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Fixed-window request counter backed by a transient.
 *
 * The window end is stored with the counter so repeated requests do not keep
 * pushing the expiry forward and lock a visitor out permanently.
 *
 * @param string $bucket Short name of the limit being enforced.
 * @param int    $max    Requests allowed within the window.
 * @param int    $window Window length in seconds.
 * @return int Seconds to wait before retrying, or 0 when the request is allowed.
 */
function lkc_check_rate_limit( $bucket, $max, $window ) {
	$max    = max( 1, absint( $max ) );
	$window = max( 1, absint( $window ) );
	$key    = 'lkc_rl_' . sanitize_key( $bucket ) . '_' . lkc_get_rate_limit_identity();
	$now    = time();
	$state  = get_transient( $key );

	if ( ! is_array( $state ) || empty( $state['reset'] ) || (int) $state['reset'] <= $now ) {
		$state = array(
			'count' => 0,
			'reset' => $now + $window,
		);
	}

	$state['count'] = (int) $state['count'] + 1;
	$remaining      = max( 1, (int) $state['reset'] - $now );
	set_transient( $key, $state, $remaining );

	return ( $state['count'] > $max ) ? $remaining : 0;
}

/**
 * Decide whether an origin may read lkc/v1 responses cross-origin.
 *
 * @param string $origin Origin already normalised with `lkc_normalize_origin()`.
 * @return bool
 */
function lkc_is_allowed_origin( $origin ) {
	/**
	 * Filter the origins allowed to read lkc/v1 responses cross-origin.
	 *
	 * Defaults to this site's own origin, which is enough for every bundled
	 * script. Add a staging or app origin here if one really needs credentialed
	 * cross-origin access; never add '*'.
	 *
	 * @param string[] $origins Absolute URLs such as 'https://app.example.test'.
	 */
	$allowed = (array) apply_filters( 'lkc_allowed_cors_origins', array( home_url() ) );

	foreach ( $allowed as $candidate ) {
		if ( '' !== $origin && $origin === lkc_normalize_origin( $candidate ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether a REST route belongs to this plugin.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return bool
 */
function lkc_is_plugin_rest_request( $request ) {
	return is_object( $request ) && method_exists( $request, 'get_route' ) && str_starts_with( (string) $request->get_route(), '/lkc/v1' );
}

/**
 * Rate limit bucket for a REST request, or an empty string for "not limited".
 *
 * Browsers post one violation report per blocked resource, so a single page view
 * can legitimately produce a burst of `/lkc/v1/csp-report` calls. That route is
 * exempt: its collector aggregates reports instead of storing them, and is
 * bounded by its own row ceiling.
 *
 * @param WP_REST_Request $request Request being dispatched.
 * @return string 'read', 'write', or ''.
 */
function lkc_rest_rate_limit_buckets( $request ) {
	if ( ! lkc_is_plugin_rest_request( $request ) ) {
		return '';
	}

	if ( '/lkc/v1/csp-report' === (string) $request->get_route() ) {
		return '';
	}

	$is_write = in_array( strtoupper( (string) $request->get_method() ), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );

	return $is_write ? 'write' : 'read';
}

/**
 * Throttle every route in the plugin's REST namespace.
 *
 * WordPress core does not rate limit the REST API. Two of the plugin routes
 * accept anonymous writes, so without a ceiling one client can grow the push
 * subscription table without bound, and the public catalog search can be used to
 * drive expensive meta/taxonomy queries.
 *
 * Runs on `rest_pre_dispatch`, after core has resolved cookie authentication, so
 * the counter already knows whether the caller is signed in.
 *
 * @param mixed           $result  Short-circuit response, null to continue.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request being dispatched.
 * @return mixed
 */
function lkc_enforce_rest_rate_limits( $result, $server, $request ) {
	if ( null !== $result ) {
		return $result;
	}

	$bucket = lkc_rest_rate_limit_buckets( $request );
	if ( '' === $bucket ) {
		return $result;
	}

	/**
	 * Filter the per-visitor REST limits for the lkc/v1 namespace.
	 *
	 * Each entry needs a `max` request count and a `window` length in seconds.
	 * Set a limit to 0 to disable that bucket.
	 *
	 * @param array $limits Keyed by 'read' and 'write'.
	 */
	$limits = apply_filters(
		'lkc_rest_rate_limits',
		array(
			'read'  => array(
				'max'    => 60,
				'window' => MINUTE_IN_SECONDS,
			),
			'write' => array(
				'max'    => 10,
				'window' => MINUTE_IN_SECONDS,
			),
		)
	);

	if ( empty( $limits[ $bucket ]['max'] ) || empty( $limits[ $bucket ]['window'] ) ) {
		return $result;
	}

	$retry_after = lkc_check_rate_limit( $bucket, $limits[ $bucket ]['max'], $limits[ $bucket ]['window'] );
	if ( ! $retry_after ) {
		return $result;
	}

	return new WP_REST_Response(
		array(
			'code'    => 'lkc_rate_limited',
			'message' => __( 'Terlalu banyak permintaan dari perangkat ini. Silakan coba lagi sebentar.', 'layar-katalog-core' ),
			'data'    => array(
				'status'      => 429,
				'retry_after' => (int) $retry_after,
			),
		),
		429,
		array(
			'Retry-After'   => (string) $retry_after,
			'Cache-Control' => 'no-store',
		)
	);
}
add_filter( 'rest_pre_dispatch', 'lkc_enforce_rest_rate_limits', 10, 3 );

/**
 * Refuse oversized request bodies on the plugin's write routes.
 *
 * The push routes accept JSON from anonymous callers. WordPress reads the whole
 * body before dispatch, so the authoritative limit belongs in the web server
 * (`client_max_body_size` in nginx, `LimitRequestBody` in Apache); this answers
 * with a correct 413 instead of parsing and validating junk.
 *
 * @param mixed           $result  Short-circuit response, null to continue.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request being dispatched.
 * @return mixed
 */
function lkc_reject_oversized_rest_body( $result, $server, $request ) {
	if ( null !== $result || ! lkc_is_plugin_rest_request( $request ) ) {
		return $result;
	}

	if ( ! in_array( strtoupper( (string) $request->get_method() ), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		return $result;
	}

	/**
	 * Filter the largest request body accepted by an lkc/v1 write route.
	 *
	 * A browser push subscription serialises to well under 1 KB, so the default
	 * leaves plenty of headroom. Return 0 to disable the check.
	 *
	 * @param int $max_bytes Maximum body size in bytes.
	 */
	$max_bytes = absint( apply_filters( 'lkc_rest_max_body_bytes', 8 * KB_IN_BYTES ) );
	if ( ! $max_bytes ) {
		return $result;
	}

	$declared = absint( $request->get_header( 'content_length' ) );
	$actual   = strlen( (string) $request->get_body() );

	if ( $declared <= $max_bytes && $actual <= $max_bytes ) {
		return $result;
	}

	return new WP_REST_Response(
		array(
			'code'    => 'lkc_payload_too_large',
			'message' => __( 'Ukuran permintaan terlalu besar.', 'layar-katalog-core' ),
			'data'    => array( 'status' => 413 ),
		),
		413,
		array( 'Cache-Control' => 'no-store' )
	);
}
add_filter( 'rest_pre_dispatch', 'lkc_reject_oversized_rest_body', 20, 3 );
