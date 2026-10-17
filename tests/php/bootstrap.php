<?php
/**
 * Test bootstrap: minimal WordPress stubs, then the real plugin source.
 *
 * The security logic under test is pure enough to run without WordPress, so the
 * harness declares just the functions the code touches and loads the shipped
 * files unchanged. Nothing here is a mock of the code being tested — if a
 * function is renamed in the plugin, these tests break loudly.
 *
 * Run with real PHP:  php tests/php/security.test.php
 * Run without PHP:    node tests/php/run-phpwasm.mjs
 *
 * @package Layar_Katalog_Core
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'LKC_TEST_ROOT' ) ) {
	define( 'LKC_TEST_ROOT', dirname( __DIR__, 2 ) );
}

$GLOBALS['lkc_test_pass'] = 0;
$GLOBALS['lkc_test_fail'] = 0;

/**
 * Record one assertion.
 *
 * @param string $label  What is being checked.
 * @param bool   $cond   Expected truth.
 * @param string $detail Optional context printed on failure.
 */
function t_assert( $label, $cond, $detail = '' ) {
	if ( $cond ) {
		$GLOBALS['lkc_test_pass']++;
		echo "PASS  {$label}\n";
	} else {
		$GLOBALS['lkc_test_fail']++;
		echo "FAIL  {$label}" . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
	}
}

/** Print a section heading. */
function t_section( $label ) {
	echo "\n--- {$label} ---\n";
}

/* ---------------------------------------------------------------- *
 * Constants
 * ---------------------------------------------------------------- */
define( 'ABSPATH', '/tmp/wp/' );

/*
 * The table installers require_once ABSPATH . 'wp-admin/includes/upgrade.php'
 * for dbDelta(). dbDelta itself is stubbed below, so the file only has to exist
 * for the require to resolve - create it if this machine does not have one.
 */
if ( ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
	@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
	@file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n/* Test harness stand-in: dbDelta() is stubbed in tests/php/bootstrap.php. */\n" );
}
define( 'KB_IN_BYTES', 1024 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'LKC_PLUGIN_FILE', LKC_TEST_ROOT . '/plugins/layar-katalog-core/layar-katalog-core.php' );
define( 'LKC_PLUGIN_DIR', LKC_TEST_ROOT . '/plugins/layar-katalog-core/' );
define( 'LKC_PLUGIN_URL', 'https://example.test/wp-content/plugins/layar-katalog-core/' );
define( 'LKC_PLUGIN_VERSION', 'test' );

/* ---------------------------------------------------------------- *
 * Mutable test state
 * ---------------------------------------------------------------- */
$GLOBALS['__filters']    = array();
$GLOBALS['__actions']    = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__options']    = array();
$GLOBALS['__theme_mods'] = array();
$GLOBALS['__user_id']    = 0;
$GLOBALS['__is_ssl']     = true;
$GLOBALS['__is_admin']   = false;
$GLOBALS['__is_preview'] = false;
$GLOBALS['__http_origin'] = '';
$_SERVER['REMOTE_ADDR']  = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';

/* ---------------------------------------------------------------- *
 * Hook registry
 * ---------------------------------------------------------------- */
function add_filter( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; }
function add_action( $tag, $cb, $p = 10, $n = 1 ) { $GLOBALS['__actions'][ $tag ][] = $cb; return true; }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['__filters'][ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	foreach ( $GLOBALS['__actions'][ $tag ] ?? array() as $cb ) {
		$cb( ...$args );
	}
}
function has_filter( $tag, $cb = false ) { return ! empty( $GLOBALS['__filters'][ $tag ] ); }

/* ---------------------------------------------------------------- *
 * WordPress function stubs
 * ---------------------------------------------------------------- */
function is_admin() { return $GLOBALS['__is_admin']; }
function is_ssl() { return $GLOBALS['__is_ssl']; }
function wp_is_serving_https() { return $GLOBALS['__is_ssl']; }
function site_url( $p = '' ) { return 'https://example.test' . ( $p ? '/' . ltrim( $p, '/' ) : '' ); }
function home_url( $p = '' ) { return 'https://example.test' . ( $p ? '/' . ltrim( $p, '/' ) : '' ); }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); }
/** Salt stub. Set $GLOBALS['__salt_override'] to simulate rotated salts. */
function wp_salt( $s = 'auth' ) {
	$prefix = isset( $GLOBALS['__salt_override'] ) ? (string) $GLOBALS['__salt_override'] : 'TEST-SALT-';
	return $prefix . $s;
}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? (string) $e : ''; }
function wp_html_excerpt( $s, $c, $e = '' ) { $s = (string) $s; return strlen( $s ) > $c ? substr( $s, 0, $c ) . $e : $s; }
function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? @parse_url( (string) $u ) : @parse_url( (string) $u, $c ); }
function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function esc_url_raw( $u, $p = null ) { return (string) filter_var( (string) $u, FILTER_SANITIZE_URL ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_generate_password( $l = 12, $s = true, $e = false ) { return substr( str_repeat( 'aB3xY9zQ7w', 4 ), 0, $l ); }
function wp_is_json_request() { return false; }
function is_user_logged_in() { return (int) $GLOBALS['__user_id'] > 0; }
function is_preview() { return (bool) $GLOBALS['__is_preview']; }
function get_current_user_id() { return (int) $GLOBALS['__user_id']; }
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ); }
function current_time( $t, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function plugin_basename( $f ) { return 'layar-katalog-core/layar-katalog-core.php'; }
function plugin_dir_path( $f ) { return dirname( (string) $f ) . '/'; }
function plugin_dir_url( $f ) { return LKC_PLUGIN_URL; }
function register_deactivation_hook( $f, $cb ) {}
function register_activation_hook( $f, $cb ) {}
function wp_next_scheduled( $h ) { return $GLOBALS['__scheduled'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['__scheduled'][ $h ] = (int) $t; return true; }
function wp_unschedule_event( $t, $h ) { unset( $GLOBALS['__scheduled'][ $h ] ); return true; }
function wp_schedule_single_event( $t, $h, $a = array() ) { return true; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['__scheduled'][ $h ] ); return 0; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__options'][ $k ] ); return true; }
function get_userdata( $id ) { return 3 === (int) $id ? (object) array( 'user_login' => 'editor' ) : null; }
function get_theme_mod( $k, $d = '' ) { return $GLOBALS['__theme_mods'][ $k ] ?? $d; }
function get_http_origin() { return (string) $GLOBALS['__http_origin']; }
function current_user_can( $c ) { return true; }
function wp_trim_words( $t, $n = 55, $m = '&hellip;' ) { return (string) $t; }
function register_rest_route( $ns, $route, $args = array() ) { $GLOBALS['__routes'][ $ns . $route ] = $args; return true; }

/*
 * Registration no-ops. Several plugin files call these at load time; the tests
 * never assert on them, they only need the files to load.
 */
function add_shortcode( $t, $c ) { $GLOBALS['__shortcodes'][ $t ] = $c; return true; }
function add_options_page( ...$a ) { return 'settings_page_stub'; }
function add_management_page( ...$a ) { return 'management_page_stub'; }
function add_submenu_page( ...$a ) { return 'submenu_page_stub'; }
function add_menu_page( ...$a ) { return 'menu_page_stub'; }
function add_settings_section( ...$a ) { return true; }
function add_settings_field( ...$a ) { return true; }
function register_setting( ...$a ) { return true; }
function register_post_type( $t, $a = array() ) { $GLOBALS['__post_types'][ $t ] = $a; return true; }
function register_taxonomy( $t, $o, $a = array() ) { $GLOBALS['__taxonomies'][ $t ] = array( 'object_types' => $o, 'args' => $a ); return true; }
function register_post_meta( $t, $k, $a = array() ) { $GLOBALS['__post_meta'][ $t ][ $k ] = $a; return true; }
function add_meta_box( ...$a ) { return true; }
function wp_register_script( ...$a ) { $GLOBALS['__assets'][ 'script:' . ( isset( $a[0] ) ? $a[0] : '' ) ] = $a; return true; }
function wp_register_style( ...$a ) { $GLOBALS['__assets'][ 'style:' . ( isset( $a[0] ) ? $a[0] : '' ) ] = $a; return true; }
function wp_enqueue_script( ...$a ) { $GLOBALS['__enqueued'][] = 'script:' . ( isset( $a[0] ) ? $a[0] : '' ); return true; }
function wp_enqueue_style( ...$a ) { $GLOBALS['__enqueued'][] = 'style:' . ( isset( $a[0] ) ? $a[0] : '' ); return true; }
function wp_add_inline_style( ...$a ) { return true; }
function wp_add_inline_script( ...$a ) { return true; }
function wp_localize_script( ...$a ) { return true; }
function wp_set_script_translations( ...$a ) { return true; }
function load_plugin_textdomain( ...$a ) { return true; }
function load_theme_textdomain( ...$a ) { return true; }
function wp_die( $m = '' ) { throw new RuntimeException( 'wp_die: ' . (string) $m ); }
function check_admin_referer( $a = -1, $q = '_wpnonce' ) { return true; }
function wp_verify_nonce( $n, $a = -1 ) { return 1; }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { return ''; }
function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return (string) $u; }
function wp_safe_redirect( $u, $s = 302 ) { $GLOBALS['__redirects'][] = array( $u, $s ); return true; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_kses_post( $s ) { return (string) $s; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function get_post_meta( $i, $k = '', $s = false ) { return $s ? '' : array(); }
function update_post_meta( $i, $k, $v ) { return true; }
function delete_post_meta( $i, $k ) { return true; }
function get_post_status( $i ) { return 'publish'; }
function get_the_title( $i = 0 ) { return 'Test title'; }
function get_permalink( $i = 0 ) { return 'https://example.test/episode/test/'; }
function get_site_icon_url( $s = 512 ) { return ''; }
function wp_get_current_user() { return (object) array( 'ID' => $GLOBALS['__user_id'], 'user_login' => 'editor' ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function dbDelta( $q ) { $GLOBALS['__dbdelta'][] = (string) $q; return array(); }
function wp_unslash_deep( $v ) { return $v; }

/*
 * WordPress pieces the activation harness needs that the security suite does
 * not: the REST server constants used in route definitions, the meta
 * registration recorder, and the rewrite flush.
 */
class WP_REST_Server {
	const READABLE   = 'GET';
	const CREATABLE  = 'POST';
	const EDITABLE   = 'PUT, PATCH';
	const DELETABLE  = 'DELETE';
	const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
}
function __return_true() { return true; }
function __return_false() { return false; }
function _x( $text, $context, $domain = null ) { return (string) $text; }
function sanitize_title( $t ) { return strtolower( str_replace( ' ', '-', (string) $t ) ); }
function register_meta( $type, $key, $args = array() ) { $GLOBALS['__meta'][] = array( 'object_type' => $type, 'meta_key' => $key, 'args' => $args ); return true; }
function flush_rewrite_rules( $hard = true ) { $GLOBALS['__flushed'][] = (bool) $hard; return true; }
function plugins_url( $path = '', $plugin = '' ) { return LKC_PLUGIN_URL . ltrim( (string) $path, '/' ); }
function get_post_types( $args = array(), $output = 'names' ) { return array( 'lk_title', 'lk_episode' ); }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) { $atts = (array) $atts; $out = array(); foreach ( (array) $pairs as $k => $v ) { $out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v; } return $out; }
function wp_list_pluck( $list, $field ) { $out = array(); foreach ( (array) $list as $item ) { $item = (array) $item; if ( isset( $item[ $field ] ) ) { $out[] = $item[ $field ]; } } return $out; }
function get_the_ID() { return 0; }
function get_post_type( $id = 0 ) { return ''; }
function wp_reset_postdata() { return true; }


class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_REST_Response {
	public $data;
	public $status;
	public $headers;
	public function __construct( $d = null, $s = 200, $h = array() ) { $this->data = $d; $this->status = $s; $this->headers = $h; }
	public function set_status( $s ) { $this->status = $s; }
	public function get_status() { return $this->status; }
}

/** Stand-in for WP_REST_Request with just the accessors the plugin uses. */
class FakeRequest {
	public $body   = '';
	public $method = 'GET';
	public $route  = '';
	public $params = array();
	private $headers = array();

	public function get_body() { return $this->body; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function get_param( $n ) { return $this->params[ $n ] ?? null; }
	public function get_params() { return $this->params; }
	public function get_json_params() { $d = json_decode( (string) $this->body, true ); return is_array( $d ) ? $d : array(); }
	public function get_header( $n ) { return $this->headers[ $this->norm( $n ) ] ?? null; }
	public function set_header( $n, $v ) { $this->headers[ $this->norm( $n ) ] = $v; }
	private function norm( $n ) { return strtolower( str_replace( '-', '_', (string) $n ) ); }
}

/**
 * Stand-in for $wpdb that records every write and answers the few reads the
 * code makes. `prepare()` keeps the arguments in a machine-readable suffix so
 * the fake can tell which table or hash a query is about.
 */
class FakeWpdb {
	public $prefix        = 'wp_';
	public $rows_affected = 0;
	public $inserts       = array();
	public $deletes       = array();
	public $queries       = array();
	public $known_hashes  = array();
	public $columns       = array();
	public $row_count     = 0;

	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function prepare( $q, ...$a ) { return $q . "\x1f" . json_encode( $a ); }
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }

	private function args_of( $q ) {
		$parts = explode( "\x1f", (string) $q, 2 );
		return isset( $parts[1] ) ? (array) json_decode( $parts[1], true ) : array();
	}

	public function get_var( $q ) {
		$q = (string) $q;
		$this->queries[] = $q;
		$args = $this->args_of( $q );

		if ( false !== strpos( $q, 'SHOW TABLES' ) ) {
			/* Pretend every plugin table exists. */
			return isset( $args[0] ) ? stripslashes( $args[0] ) : null;
		}
		if ( false !== strpos( $q, 'SELECT report_hash' ) ) {
			$hash = isset( $args[0] ) ? $args[0] : '';
			return in_array( $hash, $this->known_hashes, true ) ? $hash : null;
		}
		if ( false !== strpos( $q, 'SELECT subscription_hash' ) ) {
			$hash = isset( $args[0] ) ? $args[0] : '';
			return in_array( $hash, $this->known_hashes, true ) ? $hash : null;
		}
		if ( false !== strpos( $q, 'COUNT(*)' ) ) {
			return false !== strpos( $q, 'lkc_csp_reports' ) ? count( $this->known_hashes ) : $this->row_count;
		}
		return null;
	}

	public function get_col( $q ) {
		$this->queries[] = (string) $q;
		return false !== strpos( (string) $q, 'SHOW COLUMNS' ) ? $this->columns : array();
	}

	public function get_results( $q, $o = 'OBJECT' ) { $this->queries[] = (string) $q; return array(); }

	public function insert( $t, $d, $f = null ) {
		$this->inserts[] = array( $t, $d );
		foreach ( array( 'report_hash', 'subscription_hash' ) as $key ) {
			if ( isset( $d[ $key ] ) ) { $this->known_hashes[] = $d[ $key ]; }
		}
		$this->rows_affected = 1;
		return 1;
	}

	public function replace( $t, $d, $f = null ) { return $this->insert( $t, $d, $f ); }

	public function update( $t, $d, $w, $df = null, $wf = null ) {
		$this->queries[] = "UPDATE {$t} " . json_encode( $d );
		$this->rows_affected = 1;
		return 1;
	}

	public function delete( $t, $w, $wf = null ) {
		$this->deletes[] = array( $t, $w );
		$this->rows_affected = 1;
		return 1;
	}

	public function query( $q ) {
		$q = (string) $q;
		$this->queries[] = $q;
		if ( 0 === strpos( trim( $q ), 'DELETE' ) ) { $this->rows_affected = 1; }
		return 1;
	}
}

$wpdb = new FakeWpdb();

/* ---------------------------------------------------------------- *
 * Load the code under test
 * ---------------------------------------------------------------- */
require LKC_TEST_ROOT . '/plugins/layar-katalog-core/includes/security.php';
require LKC_TEST_ROOT . '/plugins/layar-katalog-core/includes/audit.php';
require LKC_TEST_ROOT . '/plugins/layar-katalog-core/includes/web-push.php';

/**
 * Pull one top-level function out of a file that cannot be loaded wholesale.
 *
 * The theme's functions.php and the plugin's main file both run a lot of
 * WordPress registration code at load time. Rather than stubbing all of it, the
 * individual functions under test are extracted verbatim, so the assertions still
 * run against the shipped source text.
 *
 * @param string $file Absolute path.
 * @param string $name Function name.
 * @return string PHP source of the function.
 */
function t_extract_function( $file, $name ) {
	$source = (string) file_get_contents( $file );
	$start  = strpos( $source, 'function ' . $name . '(' );
	if ( false === $start ) {
		throw new RuntimeException( "function {$name} not found in {$file}" );
	}
	$open  = strpos( $source, '{', $start );
	$depth = 0;
	$end   = $open;
	for ( $i = $open; $i < strlen( $source ); $i++ ) {
		if ( '{' === $source[ $i ] ) {
			$depth++;
		} elseif ( '}' === $source[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				$end = $i + 1;
				break;
			}
		}
	}
	return substr( $source, $start, $end - $start );
}

/**
 * Extract several functions and make them available to the test.
 *
 * @param string   $file  Absolute path.
 * @param string[] $names Function names.
 */
function t_load_functions( $file, $names ) {
	$code = '';
	foreach ( $names as $name ) {
		if ( function_exists( $name ) ) {
			continue;
		}
		$code .= t_extract_function( $file, $name ) . "\n";
	}
	if ( '' !== $code ) {
		eval( $code ); // phpcs:ignore Squiz.PHP.Eval -- test harness loading shipped source verbatim.
	}
}

t_load_functions(
	LKC_TEST_ROOT . '/themes/layar-katalog/functions.php',
	array(
		'layarkatalog_ad_locations',
		'layarkatalog_allow_font_origins_in_csp',
		'layarkatalog_audit_ad_code_change',
		'layarkatalog_add_csp_nonce_to_inline_tags',
		'layarkatalog_stamp_csp_nonce',
		/* csp-strict.test.php renders the front-end markup these produce. */
		'layarkatalog_get_ad_settings',
		'layarkatalog_ad_slot_shortcode',
		'layarkatalog_output_global_ad_code',
	)
);

t_load_functions(
	LKC_TEST_ROOT . '/plugins/layar-katalog-core/layar-katalog-core.php',
	array(
		'lkc_is_allowed_media_url',
		'lkc_is_allowed_video_url',
		'lkc_sanitize_video_url',
		'lkc_sanitize_subtitle_url',
	)
);
