<?php
/**
 * Strict Content-Security-Policy: what the plugin emits and what the theme prints.
 *
 * Two questions, both answered from the shipped source rather than from a
 * browser. What does the header actually look like once `lkc_csp_strict` is on,
 * and does the markup the theme and plugin print survive it? The second half is
 * finished by tests/js/csp-strict-roundtrip.test.js, which takes the JSON this
 * file emits at the end, parses the document with jsdom and runs the CSP matcher
 * from tests/js/csp-audit.mjs over it - the same matcher the pre-flight auditor
 * uses on a staging URL.
 *
 * @package Layar_Katalog_Core
 */

require __DIR__ . '/bootstrap.php';

/*
 * Sending headers has to be observed before this file prints anything. Once the
 * runner has captured output, headers_sent() is true and lkc_send_security_headers()
 * returns early - which would hide the difference between wp-admin and the front
 * end, the very thing being asserted.
 */
$seen_headers = array();
add_filter(
	'lkc_security_headers',
	static function ( $headers ) use ( &$seen_headers ) {
		$seen_headers[] = $headers;
		return $headers;
	}
);

$GLOBALS['__is_admin'] = true;
$GLOBALS['__is_ssl']   = true;
lkc_send_security_headers();
$admin_calls = count( $seen_headers );

$GLOBALS['__is_admin'] = false;
lkc_send_security_headers();
$front_calls   = count( $seen_headers );
$front_headers = $front_calls > $admin_calls ? $seen_headers[ $front_calls - 1 ] : array();

$GLOBALS['__filters']['lkc_security_headers'] = array();

/* ================================================================== *
 * The policy before strict mode
 * ================================================================== */
t_section( 'the shipped policy, strict mode off' );

$nonce = lkc_get_csp_nonce();
t_assert( 'a nonce exists', '' !== $nonce );
t_assert( 'the nonce uses only the URL-safe base64 alphabet', 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $nonce ), $nonce );
t_assert( 'the nonce is stable within one request', $nonce === lkc_get_csp_nonce() );

$default = lkc_get_csp_directives();
t_assert( "script-src keeps 'unsafe-inline' while strict mode is off", in_array( "'unsafe-inline'", $default['script-src'], true ) );
t_assert( 'there is no style-src-attr of its own', ! isset( $default['style-src-attr'] ) );
t_assert( 'so style attributes fall back to style-src and are allowed', in_array( "'unsafe-inline'", $default['style-src'], true ) );

$default_header = lkc_stringify_csp_directives( $default );
t_assert( 'the header is a single line', false === strpos( $default_header, "\n" ), $default_header );

/* ================================================================== *
 * Strict mode
 * ================================================================== */
t_section( 'strict mode: what ends up in the header' );

add_filter( 'lkc_csp_strict', '__return_true' );

$strict = lkc_get_csp_directives();
t_assert( "strict mode drops 'unsafe-inline' from script-src", ! in_array( "'unsafe-inline'", $strict['script-src'], true ) );
t_assert( "strict mode drops 'unsafe-inline' from style-src", ! in_array( "'unsafe-inline'", $strict['style-src'], true ) );
t_assert( 'strict mode puts the nonce in script-src', in_array( "'nonce-" . $nonce . "'", $strict['script-src'], true ) );
t_assert( 'strict mode puts the nonce in style-src', in_array( "'nonce-" . $nonce . "'", $strict['style-src'], true ) );
t_assert( 'strict mode keeps every other directive', count( array_diff_key( $default, $strict ) ) === 0 );

t_assert(
	"style attributes keep working: style-src-attr carries 'unsafe-inline'",
	array( "'unsafe-inline'" ) === $strict['style-src-attr'],
	isset( $strict['style-src-attr'] ) ? implode( ' ', $strict['style-src-attr'] ) : 'absent'
);
t_assert( 'inline event handlers stay blocked: script-src-attr is not loosened', ! isset( $strict['script-src-attr'] ) );
t_assert( "object-src stays 'none' under strict mode", array( "'none'" ) === $strict['object-src'] );

$strict_header = lkc_stringify_csp_directives( $strict );
t_assert( 'the strict header carries the nonce', false !== strpos( $strict_header, "'nonce-" . $nonce . "'" ) );
t_assert( 'the strict header names style-src-attr', false !== strpos( $strict_header, 'style-src-attr' ) );
t_assert( "the strict header has no 'unsafe-eval' anywhere", false === strpos( $strict_header, "'unsafe-eval'" ) );
t_assert( 'the strict header is still one line', false === strpos( $strict_header, "\n" ) );

$delivery = lkc_get_content_security_policy_header();
t_assert( 'strict mode is still delivered report-only until the operator changes lkc_csp_mode', array( 'Content-Security-Policy-Report-Only' ) === array_keys( $delivery ), implode( ',', array_keys( $delivery ) ) );
t_assert( 'and the delivered value is the strict one', $strict_header === reset( $delivery ) );

add_filter( 'lkc_csp_mode', '__return_enforce_mode' );
function __return_enforce_mode() {
	return 'enforce';
}
t_assert( 'enforce mode renames the header', array( 'Content-Security-Policy' ) === array_keys( lkc_get_content_security_policy_header() ) );
$GLOBALS['__filters']['lkc_csp_mode'] = array();

/* The concession is opt-out, for a site whose markup has no style attributes. */
add_filter( 'lkc_csp_strict_style_attributes', '__return_false' );
$locked = lkc_get_csp_directives();
t_assert( 'a site with no style attributes can drop the concession', ! isset( $locked['style-src-attr'] ) );
t_assert( 'and nothing else about the policy changes', array( 'style-src-attr' ) === array_keys( array_diff_key( $strict, $locked ) ) );
t_assert( 'the nonce is still there without the concession', in_array( "'nonce-" . $nonce . "'", $locked['style-src'], true ) );
$locked_header = lkc_stringify_csp_directives( $locked );
$GLOBALS['__filters']['lkc_csp_strict_style_attributes'] = array();

/* What an allowlist snippet pasted into mu-plugins does to the same header. */
add_filter(
	'lkc_content_security_policy',
	static function ( $policy ) {
		$policy['script-src'][] = 'https://pagead2.googlesyndication.com';
		return $policy;
	}
);
$allowlisted = lkc_get_csp_directives();
t_assert( 'an allowlisted host survives strict mode', in_array( 'https://pagead2.googlesyndication.com', $allowlisted['script-src'], true ) );
t_assert( 'and the nonce is added after the operator filter ran', in_array( "'nonce-" . $nonce . "'", $allowlisted['script-src'], true ) );
t_assert( "the operator's host does not drag 'unsafe-inline' back in", ! in_array( "'unsafe-inline'", $allowlisted['script-src'], true ) );
$allowlisted_header = lkc_stringify_csp_directives( $allowlisted );
$GLOBALS['__filters']['lkc_content_security_policy'] = array();

/* ================================================================== *
 * The admin screens
 * ================================================================== */
/*
 * The header captured above was built before any filter in this file ran, so it
 * must equal the shipped policy. Everything after this point assumes strict mode
 * is on, which the reset below would otherwise have switched off again.
 */
$GLOBALS['__filters']['lkc_csp_strict']                     = array();
$GLOBALS['__filters']['lkc_content_security_policy']        = array();
$GLOBALS['__filters']['lkc_csp_strict_style_attributes']    = array();
$front_header_expected = lkc_stringify_csp_directives( lkc_get_csp_directives() );
add_filter( 'lkc_csp_strict', '__return_true' );

t_section( 'wp-admin is not covered by the header' );

t_assert( 'no security headers are computed inside wp-admin, so the admin screens keep their inline confirm() forms', 0 === $admin_calls, (string) $admin_calls );
t_assert( 'the same call on the front end does compute them', $front_calls > $admin_calls, (string) $front_calls );
t_assert( 'and they include the Content-Security-Policy', isset( $front_headers['Content-Security-Policy-Report-Only'] ) );
t_assert( 'and HSTS, because this response is https', isset( $front_headers['Strict-Transport-Security'] ) && false !== strpos( $front_headers['Strict-Transport-Security'], 'includeSubDomains' ) );
t_assert( 'and nosniff', isset( $front_headers['X-Content-Type-Options'] ) && 'nosniff' === $front_headers['X-Content-Type-Options'] );
t_assert( 'and a Permissions-Policy that grants nothing', isset( $front_headers['Permissions-Policy'] ) && false === strpos( $front_headers['Permissions-Policy'], 'camera=*' ) );
t_assert( 'the delivered policy is the one lkc_get_csp_directives() built', $front_header_expected === $front_headers['Content-Security-Policy-Report-Only'], $front_headers['Content-Security-Policy-Report-Only'] );

/* ================================================================== *
 * The markup the theme prints
 * ================================================================== */
t_section( 'the markup the theme prints under strict mode' );

update_option(
	'layarkatalog_ads',
	array(
		'global_head' => array(
			'enabled' => true,
			'code'    => '<script src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js" async></script><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script>',
		),
		'slots'       => array(
			'home_after_hero' => array(
				'enabled'    => true,
				'code'       => '<ins class="adsbygoogle" data-ad-client="ca-pub-1"></ins>',
				'min_height' => 250,
			),
		),
	)
);

$slot = layarkatalog_ad_slot_shortcode( array( 'slot' => 'home_after_hero' ) );
t_assert( 'an ad slot carries no style attribute', false === stripos( $slot, 'style="' ), $slot );
t_assert( 'its reserved height is a style element instead', false !== strpos( $slot, '<style nonce="' . $nonce . '">#lk-ad-slot-content-1{min-height:250px}</style>' ), $slot );
t_assert( 'the element that rule targets is in the same markup', false !== strpos( $slot, '<div class="lk-ad-slot__content" id="lk-ad-slot-content-1">' ), $slot );
t_assert( 'the administrator code inside it is untouched', false !== strpos( $slot, '<ins class="adsbygoogle" data-ad-client="ca-pub-1"></ins>' ) );
t_assert( 'an unknown slot prints nothing', '' === layarkatalog_ad_slot_shortcode( array( 'slot' => 'nope' ) ) );

$second = layarkatalog_ad_slot_shortcode( array( 'slot' => 'home_after_hero' ) );
t_assert( 'a second render gets its own id, so one slot twice on a page still works', false !== strpos( $second, '#lk-ad-slot-content-2{' ), $second );

update_option(
	'layarkatalog_ads',
	array(
		'slots' => array(
			'home_after_hero' => array(
				'enabled'    => true,
				'code'       => '<ins class="adsbygoogle"></ins>',
				'min_height' => 0,
			),
		),
	)
);
$flat = layarkatalog_ad_slot_shortcode( array( 'slot' => 'home_after_hero' ) );
t_assert( 'a slot without a reserved height prints no style element', false === stripos( $flat, '<style' ), $flat );
t_assert( 'and no id it does not need', false === stripos( $flat, ' id="' ), $flat );

update_option(
	'layarkatalog_ads',
	array(
		'global_head' => array(
			'enabled' => true,
			'code'    => '<script src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js" async></script><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script>',
		),
		'slots'       => array(
			'home_after_hero' => array(
				'enabled'    => true,
				'code'       => '<ins class="adsbygoogle" data-ad-client="ca-pub-1"></ins>',
				'min_height' => 250,
			),
		),
	)
);

ob_start();
layarkatalog_output_global_ad_code();
$head_code = (string) ob_get_clean();
t_assert( "the administrator's external loader is left alone, because a URL is matched by URL", false !== strpos( $head_code, '<script src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js" async></script>' ), $head_code );
t_assert( 'but their inline push gets the nonce from the header', false !== strpos( $head_code, '<script nonce="' . $nonce . '">(adsbygoogle=window.adsbygoogle||[]).push({});</script>' ), $head_code );

$slot = layarkatalog_ad_slot_shortcode( array( 'slot' => 'home_after_hero' ) );

/* ================================================================== *
 * The document the JavaScript round trip audits
 * ================================================================== */
t_section( 'the round trip' );

$document = '<!DOCTYPE html><html lang="id"><head>'
	. '<meta charset="utf-8">'
	. '<title>Katalog — Layar Katalog</title>'
	. '<link rel="stylesheet" href="https://example.test/wp-content/themes/layar-katalog/style.css">'
	. '<script src="https://example.test/wp-content/plugins/layar-katalog-core/player.js" id="lkc-player-js"></script>'
	. $head_code
	. '</head><body>'
	. '<header class="site-header"><nav class="wp-block-navigation layarkatalog-header-nav"><a href="/">Beranda</a></nav></header>'
	. '<main class="lk-catalog">'
	. $slot
	. '<article class="lk-card" style="min-height:120px">a style attribute from a page builder, not from this theme</article>'
	. '<button type="button" class="lkc-watchlist-toggle" onclick="lkcToggle(this)">an inline handler from a page builder</button>'
	. '<img src="https://img.example.test/poster.jpg" alt="Sampul">'
	. '<video src="https://example.test/wp-content/uploads/episode-1.mp4" poster="https://img.example.test/still.jpg"></video>'
	. '</main></body></html>';

$GLOBALS['__filters']['lkc_csp_strict'] = array();
$report_only_header = lkc_stringify_csp_directives( lkc_get_csp_directives() );

echo 'CSP_ROUNDTRIP_JSON=' . json_encode(
	array(
		'nonce'              => $nonce,
		'document_url'       => 'https://example.test/katalog/',
		'html'               => $document,
		'header_default'     => $report_only_header,
		'header_strict'      => $strict_header,
		'header_allowlisted' => $allowlisted_header,
		'header_locked'      => $locked_header,
	)
) . "\n";

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
