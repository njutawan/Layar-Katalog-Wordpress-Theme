<?php
/**
 * Activation, registration and callback-integrity tests.
 *
 * Companion to tests/wp/smoke-activate.sh. That script proves the result is
 * present on a running site; this suite proves the registration logic itself is
 * correct - what arguments it passes, which tables it creates, what it
 * schedules - and it can run anywhere, including CI and a machine with no
 * WordPress at all.
 *
 * Three classes of defect are targeted here:
 *
 *  - a registration that silently stops happening (F-15 was a parse error
 *    inside lkc_register_meta(), which meant no catalog meta was ever exposed
 *    to the REST API);
 *  - a hook or route pointing at a function that does not exist, which is a
 *    fatal error on the first request that fires it and which `php -l` cannot
 *    see;
 *  - a route registered without an explicit permission_callback, which in
 *    WordPress means "anyone may call this".
 *
 * @package Layar_Katalog_Core
 */

require __DIR__ . '/bootstrap.php';

/*
 * bootstrap.php loads the three files the security suite needs. The activation
 * path also installs the engagement tables, so that file joins them here.
 */
require_once LKC_PLUGIN_DIR . 'includes/engagement.php';

t_load_functions(
	LKC_PLUGIN_DIR . 'layar-katalog-core.php',
	array(
		'lkc_register_post_types',
		'lkc_register_taxonomies',
		'lkc_register_meta',
		'lkc_register_frontend_assets',
		'lkc_register_catalog_search_route',
		'lkc_activate',
		'lkc_deactivate',
	)
);

/* ------------------------------------------------------------------ *
 * Scanning helpers
 * ------------------------------------------------------------------ */

/**
 * Files that register hooks, routes and assets.
 *
 * An explicit list rather than a directory walk: the WebAssembly runner only
 * mirrors the files it is told to, so walking the tree would scan a different
 * set of files there than under a native PHP install.
 *
 * @return string[] Absolute paths that exist in this environment.
 */
function t_registration_files() {
	$relative = array(
		'plugins/layar-katalog-core/layar-katalog-core.php',
		'plugins/layar-katalog-core/includes/security.php',
		'plugins/layar-katalog-core/includes/audit.php',
		'plugins/layar-katalog-core/includes/engagement.php',
		'plugins/layar-katalog-core/includes/web-push.php',
		'themes/layar-katalog/functions.php',
	);

	$found = array();
	foreach ( $relative as $path ) {
		$absolute = LKC_TEST_ROOT . '/' . $path;
		if ( file_exists( $absolute ) ) {
			$found[ $path ] = (string) file_get_contents( $absolute );
		}
	}

	return $found;
}

/**
 * Function names defined in a source string.
 *
 * @param string $source PHP source.
 * @return string[] Lower-cased names.
 */
function t_extract_function_names( $source ) {
	$names = array();
	if ( preg_match_all( '/^[ \t]*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', (string) $source, $matches ) ) {
		foreach ( $matches[1] as $name ) {
			$names[] = strtolower( $name );
		}
	}
	return $names;
}

/**
 * Callback names registered from a source string.
 *
 * Covers `add_action`/`add_filter`/`add_shortcode` with a string callback, the
 * activation hooks (whose first argument is `__FILE__`), and the callback keys
 * inside route and meta argument arrays.
 *
 * @param string $source PHP source.
 * @return array List of array( 'hook' => string, 'callback' => string ).
 */
function t_extract_callback_registrations( $source ) {
	$source        = (string) $source;
	$registrations = array();

	$patterns = array(
		'/\b(?:add_action|add_filter|add_shortcode)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',
		'/\bregister_(?:activation|deactivation)_hook\(\s*__FILE__\s*,\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*\)/',
		'/[\'"](?:callback|permission_callback|sanitize_callback|validate_callback)[\'"]\s*=>\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/',
	);

	foreach ( $patterns as $index => $pattern ) {
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $matches as $match ) {
			/* The second and third patterns capture the callback first. */
			if ( 0 === $index ) {
				$registrations[] = array( 'hook' => $match[1], 'callback' => $match[2] );
			} elseif ( 1 === $index ) {
				$registrations[] = array( 'hook' => 'activation', 'callback' => $match[1] );
			} else {
				$registrations[] = array( 'hook' => 'argument', 'callback' => $match[1] );
			}
		}
	}

	return $registrations;
}

/**
 * Whether a callback belongs to this project rather than to WordPress core.
 *
 * @param string $callback Function name.
 * @return bool
 */
function t_is_project_callback( $callback ) {
	return 0 === strpos( $callback, 'lkc_' ) || 0 === strpos( $callback, 'layarkatalog_' );
}

/* ================================================================== *
 * The scanner itself, so a clean result cannot mean a broken scanner
 * ================================================================== */
t_section( 'callback scanner self-test' );

$fixture = "<?php\nfunction alpha() {}\nadd_action( 'init', 'alpha' );\nadd_filter( 'the_title', 'beta' );\nadd_shortcode( 'x', 'gamma' );\nregister_activation_hook( __FILE__, 'delta' );\n\$a = array( 'callback' => 'epsilon', 'permission_callback' => '__return_true' );\n";
t_assert( 'functions are found', array( 'alpha' ) === t_extract_function_names( $fixture ), json_encode( t_extract_function_names( $fixture ) ) );
$found = t_extract_callback_registrations( $fixture );
$names = array();
foreach ( $found as $entry ) {
	$names[] = $entry['callback'];
}
sort( $names );
t_assert( 'every registration form is captured', array( '__return_true', 'alpha', 'beta', 'delta', 'epsilon', 'gamma' ) === $names, json_encode( $names ) );
t_assert( 'project callbacks are recognised', true === t_is_project_callback( 'lkc_activate' ) && true === t_is_project_callback( 'layarkatalog_setup' ) );
t_assert( 'core callbacks are not claimed as project ones', false === t_is_project_callback( '__return_true' ) && false === t_is_project_callback( 'wpautop' ) );

/* ================================================================== *
 * No hook, route or meta argument points at a missing function
 * ================================================================== */
t_section( 'no registered callback is missing' );

$files      = t_registration_files();
$defined    = array();
$registered = array();
foreach ( $files as $path => $source ) {
	foreach ( t_extract_function_names( $source ) as $name ) {
		$defined[ $name ] = $path;
	}
	foreach ( t_extract_callback_registrations( $source ) as $entry ) {
		$entry['file'] = $path;
		$registered[]  = $entry;
	}
}

t_assert( 'the shipped sources are scanned', count( $files ) >= 6, (string) count( $files ) );
t_assert( 'registrations were found to check', count( $registered ) > 50, (string) count( $registered ) );

$dangling = array();
foreach ( $registered as $entry ) {
	$callback = strtolower( $entry['callback'] );
	if ( t_is_project_callback( $callback ) ) {
		if ( ! isset( $defined[ $callback ] ) ) {
			$dangling[] = $entry['hook'] . ' → ' . $entry['callback'] . ' (' . $entry['file'] . ')';
		}
		continue;
	}
	/* Core callbacks are only reported when the harness knows they are absent. */
	if ( ! function_exists( $entry['callback'] ) && in_array( $entry['callback'], array( '__return_true', '__return_false' ), true ) ) {
		$dangling[] = $entry['hook'] . ' → ' . $entry['callback'] . ' (core stub missing)';
	}
}
t_assert( 'every project callback registered on a hook or route exists', array() === $dangling, json_encode( array_values( array_unique( $dangling ) ) ) );

/* ================================================================== *
 * The activation hook
 * ================================================================== */
t_section( 'lkc_activate() — what activation actually does' );

$GLOBALS['__dbdelta']    = array();
$GLOBALS['__flushed']    = array();
$GLOBALS['__scheduled']  = array();
$GLOBALS['__post_types'] = array();
$GLOBALS['__taxonomies'] = array();
$GLOBALS['__post_meta']  = array();
$GLOBALS['__routes']     = array();
$GLOBALS['__assets']     = array();
$GLOBALS['__options']    = array();

lkc_activate();

t_assert( 'rewrite rules are flushed exactly once', 1 === count( $GLOBALS['__flushed'] ), json_encode( $GLOBALS['__flushed'] ) );
t_assert( 'the flush is a hard flush', true === reset( $GLOBALS['__flushed'] ), var_export( reset( $GLOBALS['__flushed'] ), true ) );
t_assert( 'the rewrite version is recorded', '1.3.0' === get_option( 'lkc_rewrite_version' ), var_export( get_option( 'lkc_rewrite_version' ), true ) );
t_assert( 'both post types are registered', isset( $GLOBALS['__post_types']['lk_title'], $GLOBALS['__post_types']['lk_episode'] ), json_encode( array_keys( $GLOBALS['__post_types'] ) ) );
t_assert( 'the genre taxonomy is registered', isset( $GLOBALS['__taxonomies']['lk_genre'] ), json_encode( array_keys( $GLOBALS['__taxonomies'] ) ) );
t_assert( 'the engagement schema version reaches 1.1', '1.1' === get_option( 'lkc_engagement_db_version' ), var_export( get_option( 'lkc_engagement_db_version' ), true ) );
t_assert( 'the security schema version is recorded', '1.0' === get_option( 'lkc_security_db_version' ), var_export( get_option( 'lkc_security_db_version' ), true ) );
t_assert( 'dbDelta ran once per plugin table', 4 === count( $GLOBALS['__dbdelta'] ), (string) count( $GLOBALS['__dbdelta'] ) );
t_assert( 'log pruning is scheduled by activation', isset( $GLOBALS['__scheduled']['lkc_prune_security_logs'] ), json_encode( array_keys( $GLOBALS['__scheduled'] ) ) );

$ddl = implode( "\n", $GLOBALS['__dbdelta'] );
foreach ( array( 'lkc_user_ratings', 'lkc_push_subscriptions', 'lkc_audit_log', 'lkc_csp_reports' ) as $table ) {
	t_assert( "CREATE TABLE for {$table} is issued", false !== strpos( $ddl, $table ) );
}
t_assert( 'the push table declares failure_count', false !== strpos( $ddl, 'failure_count' ) );
t_assert( 'the push table declares last_failure_at', false !== strpos( $ddl, 'last_failure_at' ) );
t_assert( 'the audit table stores a hashed IP, not a raw one', false !== strpos( $ddl, 'ip_hash' ) && 0 === preg_match( '/\bip\s+varchar/', $ddl ), $ddl );
t_assert( 'the audit table stores a hashed user agent', false !== strpos( $ddl, 'ua_hash' ) );
t_assert( 'no table stores VAPID key material', false === stripos( $ddl, 'vapid' ) && false === stripos( $ddl, 'private_key' ) );
t_assert( 'every plugin table declares a charset', 4 === substr_count( $ddl, 'DEFAULT CHARSET=utf8mb4' ), (string) substr_count( $ddl, 'CHARSET' ) );

lkc_maybe_schedule_push_pruning();
t_assert( 'stale-subscription pruning is scheduled daily', isset( $GLOBALS['__scheduled']['lkc_prune_stale_push_subscriptions'] ), json_encode( array_keys( $GLOBALS['__scheduled'] ) ) );

lkc_deactivate();
t_assert( 'deactivation flushes rewrite rules too', 2 === count( $GLOBALS['__flushed'] ), (string) count( $GLOBALS['__flushed'] ) );

/* ================================================================== *
 * Post type and taxonomy arguments
 * ================================================================== */
t_section( 'registered post types and taxonomy' );

$title   = $GLOBALS['__post_types']['lk_title'];
$episode = $GLOBALS['__post_types']['lk_episode'];

t_assert( 'the catalog post type is public', ! empty( $title['public'] ) );
t_assert( 'the catalog post type is exposed to REST', ! empty( $title['show_in_rest'] ) );
t_assert( 'the catalog post type maps meta capabilities', ! empty( $title['map_meta_cap'] ), 'without map_meta_cap, current_user_can() on a single post is not reliable' );
t_assert( 'the catalog post type uses post capabilities', 'post' === ( isset( $title['capability_type'] ) ? $title['capability_type'] : '' ) );
t_assert( 'the catalog archive slug is katalog', 'katalog' === ( isset( $title['rewrite']['slug'] ) ? $title['rewrite']['slug'] : '' ) );
t_assert( 'the episode post type maps meta capabilities', ! empty( $episode['map_meta_cap'] ) );
t_assert( 'the episode post type is exposed to REST', ! empty( $episode['show_in_rest'] ) );
t_assert( 'the episode archive slug is episode', 'episode' === ( isset( $episode['rewrite']['slug'] ) ? $episode['rewrite']['slug'] : '' ) );

$genre = $GLOBALS['__taxonomies']['lk_genre'];
t_assert( 'the genre taxonomy is attached to the catalog post type', in_array( 'lk_title', (array) $genre['object_types'], true ), json_encode( $genre['object_types'] ) );
t_assert( 'the genre taxonomy is exposed to REST', ! empty( $genre['args']['show_in_rest'] ) );

/* ================================================================== *
 * Meta registration — the F-15 regression
 * ================================================================== */
t_section( 'lkc_register_meta() — catalog and episode meta' );

lkc_register_meta();

$title_keys   = array_keys( isset( $GLOBALS['__post_meta']['lk_title'] ) ? $GLOBALS['__post_meta']['lk_title'] : array() );
$episode_keys = array_keys( isset( $GLOBALS['__post_meta']['lk_episode'] ) ? $GLOBALS['__post_meta']['lk_episode'] : array() );

$expected_title = array( '_lk_format', '_lk_year', '_lk_status', '_lk_rating', '_lk_featured', '_lk_original_title', '_lk_producers', '_lk_duration', '_lk_seo_title', '_lk_seo_description' );
$expected_epi   = array( '_lk_parent_title', '_lk_season', '_lk_episode_number', '_lk_runtime', '_lk_video_url', '_lk_video_url_2', '_lk_video_url_3', '_lk_subtitle_url', '_lk_subtitle_label', '_lk_video_url_label', '_lk_video_url_2_label', '_lk_video_url_3_label', '_lk_seo_title', '_lk_seo_description' );

t_assert( 'every catalog meta key is registered on lk_title', array() === array_diff( $expected_title, $title_keys ), json_encode( array_values( array_diff( $expected_title, $title_keys ) ) ) );
t_assert( 'no stray meta key is registered on lk_title', array() === array_diff( $title_keys, $expected_title ), json_encode( array_values( array_diff( $title_keys, $expected_title ) ) ) );
t_assert( 'every episode meta key is registered on lk_episode', array() === array_diff( $expected_epi, $episode_keys ), json_encode( array_values( array_diff( $expected_epi, $episode_keys ) ) ) );
t_assert( 'no stray meta key is registered on lk_episode', array() === array_diff( $episode_keys, $expected_epi ), json_encode( array_values( array_diff( $episode_keys, $expected_epi ) ) ) );

/* The two halves must not have collapsed into one, which is what F-15 did. */
t_assert( 'episode-only meta is not registered on the catalog', ! isset( $GLOBALS['__post_meta']['lk_title']['_lk_episode_number'] ) );
t_assert( 'catalog-only meta is not registered on episodes', ! isset( $GLOBALS['__post_meta']['lk_episode']['_lk_year'] ) );

$no_rest = array();
$no_sanitize = array();
$no_auth = array();
$not_single = array();
$bad_sanitize = array();
foreach ( array( 'lk_title', 'lk_episode' ) as $post_type ) {
	foreach ( $GLOBALS['__post_meta'][ $post_type ] as $key => $args ) {
		if ( empty( $args['show_in_rest'] ) ) {
			$no_rest[] = $post_type . ':' . $key;
		}
		if ( empty( $args['sanitize_callback'] ) ) {
			$no_sanitize[] = $post_type . ':' . $key;
		} elseif ( t_is_project_callback( $args['sanitize_callback'] ) && ! isset( $defined[ strtolower( $args['sanitize_callback'] ) ] ) ) {
			$bad_sanitize[] = $post_type . ':' . $key . ' → ' . $args['sanitize_callback'];
		}
		if ( empty( $args['auth_callback'] ) || ! is_callable( $args['auth_callback'] ) ) {
			$no_auth[] = $post_type . ':' . $key;
		}
		if ( empty( $args['single'] ) ) {
			$not_single[] = $post_type . ':' . $key;
		}
	}
}
t_assert( 'every meta key is exposed to REST', array() === $no_rest, json_encode( $no_rest ) );
t_assert( 'every meta key has a sanitize_callback', array() === $no_sanitize, json_encode( $no_sanitize ) );
t_assert( 'every sanitize_callback exists in the shipped source', array() === $bad_sanitize, json_encode( $bad_sanitize ) );
t_assert( 'every meta key is capability-gated by an auth_callback', array() === $no_auth, json_encode( $no_auth ) );
t_assert( 'every meta key is declared single-valued', array() === $not_single, json_encode( $not_single ) );
t_assert( 'the video URL meta declares a string type', 'string' === $GLOBALS['__post_meta']['lk_episode']['_lk_video_url']['type'] );
t_assert( 'the year meta declares an integer type', 'integer' === $GLOBALS['__post_meta']['lk_title']['_lk_year']['type'] );
t_assert( 'the rating meta declares a number type', 'number' === $GLOBALS['__post_meta']['lk_title']['_lk_rating']['type'], var_export( $GLOBALS['__post_meta']['lk_title']['_lk_rating']['type'], true ) );

/* ================================================================== *
 * REST routes
 * ================================================================== */
t_section( 'lkc/v1 route registration' );

$GLOBALS['__routes'] = array();
lkc_register_catalog_search_route();
lkc_register_user_rating_routes();
lkc_register_push_routes();
lkc_register_csp_report_route();

$expected_routes = array(
	'lkc/v1/catalog/search',
	'lkc/v1/push/key',
	'lkc/v1/push/subscribe',
	'lkc/v1/push/unsubscribe',
	'lkc/v1/csp-report',
);
foreach ( $expected_routes as $route ) {
	t_assert( "route {$route} is registered", isset( $GLOBALS['__routes'][ $route ] ), json_encode( array_keys( $GLOBALS['__routes'] ) ) );
}
t_assert( 'a ratings route is registered', 1 === count( preg_grep( '#^lkc/v1/ratings#', array_keys( $GLOBALS['__routes'] ) ) ), json_encode( array_keys( $GLOBALS['__routes'] ) ) );

$no_permission = array();
$no_methods    = array();
$bad_callbacks = array();
$definitions   = 0;
foreach ( $GLOBALS['__routes'] as $route => $args ) {
	/* A route may be registered as one definition or as a list of them. */
	$list = ( isset( $args['methods'] ) || isset( $args['callback'] ) ) ? array( $args ) : (array) $args;
	foreach ( $list as $definition ) {
		++$definitions;
		if ( empty( $definition['permission_callback'] ) ) {
			$no_permission[] = $route;
		}
		if ( empty( $definition['methods'] ) ) {
			$no_methods[] = $route;
		}
		foreach ( array( 'callback', 'permission_callback' ) as $slot ) {
			if ( empty( $definition[ $slot ] ) || ! is_string( $definition[ $slot ] ) ) {
				continue;
			}
			$name = $definition[ $slot ];
			if ( t_is_project_callback( $name ) ) {
				if ( ! isset( $defined[ strtolower( $name ) ] ) ) {
					$bad_callbacks[] = $route . ' ' . $slot . ' → ' . $name;
				}
			} elseif ( ! function_exists( $name ) ) {
				$bad_callbacks[] = $route . ' ' . $slot . ' → ' . $name . ' (unknown core callback)';
			}
		}
	}
}
t_assert( 'route definitions were collected', $definitions >= 6, (string) $definitions );
t_assert( 'every route declares an explicit permission_callback', array() === $no_permission, json_encode( array_values( array_unique( $no_permission ) ) ) . ' — a missing permission_callback means anyone may call the route' );
t_assert( 'every route declares its HTTP methods', array() === $no_methods, json_encode( array_values( array_unique( $no_methods ) ) ) );
t_assert( 'every route callback exists in the shipped source', array() === $bad_callbacks, json_encode( array_values( array_unique( $bad_callbacks ) ) ) );

$rating_route = preg_grep( '#^lkc/v1/ratings#', array_keys( $GLOBALS['__routes'] ) );
$rating_args  = $GLOBALS['__routes'][ reset( $rating_route ) ];
$rating_list  = ( isset( $rating_args['methods'] ) ) ? array( $rating_args ) : (array) $rating_args;

/* Reading an aggregate rating is public; writing one must not be. That split is
   the IDOR guard for point 14 of the audit, so assert both halves of it. */
$rating_read  = null;
$rating_write = null;
foreach ( $rating_list as $definition ) {
	$methods = isset( $definition['methods'] ) ? (string) $definition['methods'] : '';
	if ( false !== strpos( $methods, 'POST' ) || false !== strpos( $methods, 'PUT' ) || false !== strpos( $methods, 'PATCH' ) || false !== strpos( $methods, 'DELETE' ) ) {
		$rating_write = $definition;
	} else {
		$rating_read = $definition;
	}
}
t_assert( 'a rating can be read publicly', null !== $rating_read && '__return_true' === $rating_read['permission_callback'], json_encode( $rating_read ) );
t_assert( 'writing a rating has its own definition', null !== $rating_write, json_encode( $rating_list ) );
t_assert( 'writing a rating is capability-gated, not open to everyone', null !== $rating_write && '__return_true' !== $rating_write['permission_callback'], json_encode( $rating_write ) );
t_assert( 'writing a rating checks the post type of the target', null !== $rating_write && 'lkc_rest_can_rate_title' === $rating_write['permission_callback'], json_encode( $rating_write ) );
t_assert( 'the rating write callback exists', null !== $rating_write && isset( $defined['lkc_rest_can_rate_title'] ) );

$subscribe = $GLOBALS['__routes']['lkc/v1/push/subscribe'];
$subscribe = ( isset( $subscribe['methods'] ) ) ? array( $subscribe ) : (array) $subscribe;
t_assert( 'the subscribe route is POST-only', WP_REST_Server::CREATABLE === $subscribe[0]['methods'], var_export( $subscribe[0]['methods'], true ) );
t_assert( 'the subscribe route validates its input', isset( $subscribe[0]['args']['endpoint'], $subscribe[0]['args']['keys'] ), json_encode( array_keys( isset( $subscribe[0]['args'] ) ? $subscribe[0]['args'] : array() ) ) );
t_assert( 'the subscribe route requires an endpoint', ! empty( $subscribe[0]['args']['endpoint']['required'] ) );
t_assert( 'the subscribe route requires keys', ! empty( $subscribe[0]['args']['keys']['required'] ) );

$search = $GLOBALS['__routes']['lkc/v1/catalog/search'];
$search = ( isset( $search['methods'] ) ) ? array( $search ) : (array) $search;
t_assert( 'catalog search is read-only', WP_REST_Server::READABLE === $search[0]['methods'], var_export( $search[0]['methods'], true ) );
t_assert( 'catalog search sanitises every query argument', isset( $search[0]['args']['q']['sanitize_callback'], $search[0]['args']['genre']['sanitize_callback'], $search[0]['args']['year']['sanitize_callback'] ), json_encode( array_keys( isset( $search[0]['args'] ) ? $search[0]['args'] : array() ) ) );

$report = $GLOBALS['__routes']['lkc/v1/csp-report'];
$report = ( isset( $report['methods'] ) ) ? array( $report ) : (array) $report;
t_assert( 'the CSP report route is POST-only', WP_REST_Server::CREATABLE === $report[0]['methods'], var_export( $report[0]['methods'], true ) );

/* ================================================================== *
 * Front-end assets
 * ================================================================== */
t_section( 'front-end asset registration' );

$GLOBALS['__assets'] = array();
lkc_register_frontend_assets();

$player = isset( $GLOBALS['__assets']['script:lkc-player'] ) ? $GLOBALS['__assets']['script:lkc-player'] : null;
t_assert( 'the player script is registered', null !== $player, json_encode( array_keys( $GLOBALS['__assets'] ) ) );
if ( null !== $player ) {
	t_assert( 'the player script comes from the plugin directory over the site origin', 0 === strpos( (string) $player[1], 'https://example.test/wp-content/plugins/layar-katalog-core/' ), (string) $player[1] );
	t_assert( 'the player script is cache-busted by file time', (string) filemtime( LKC_PLUGIN_DIR . 'player.js' ) === (string) $player[3], var_export( $player[3], true ) );
	t_assert( 'the cache-buster is not the plugin version fallback', LKC_PLUGIN_VERSION !== (string) $player[3], var_export( $player[3], true ) );
	t_assert( 'the player script is deferred to the footer', true === $player[4], var_export( $player[4], true ) );
	t_assert( 'the player script has no dependencies', array() === (array) $player[2], json_encode( $player[2] ) );
}

$insecure = array();
foreach ( $GLOBALS['__assets'] as $handle => $args ) {
	if ( isset( $args[1] ) && is_string( $args[1] ) && 0 === strpos( $args[1], 'http://' ) ) {
		$insecure[] = $handle . ' → ' . $args[1];
	}
}
t_assert( 'no registered asset is served over plain http', array() === $insecure, json_encode( $insecure ) );

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
