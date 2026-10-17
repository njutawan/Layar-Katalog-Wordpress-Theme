#!/usr/bin/env bash
#
# Activation smoke test for Layar Katalog on a real WordPress install.
#
# The unit suites in tests/ prove the logic is correct against stubbed
# WordPress. They cannot prove that the plugin activates on a real site, that
# the tables really get created, that the routes really get registered, or that
# the security headers really reach a browser. This script does that, and it is
# the "activation testing on staging" item from docs/SECURITY-AUDIT.md.
#
# Run it from the WordPress root (or pass --path), against a STAGING site:
#
#   bash tests/wp/smoke-activate.sh
#   bash tests/wp/smoke-activate.sh --activate        # force a deactivate/activate cycle
#   bash tests/wp/smoke-activate.sh --skip-rate-limit # skip the 12-request limiter probe
#   bash tests/wp/smoke-activate.sh --skip-http       # skip every curl-based check
#
# Exit status is 0 when every check passed or was skipped, 1 when any failed.
# Nothing here writes to the database except the rate-limit probe, which only
# touches short-lived transients, and --activate, which is explicit.

set -uo pipefail

PLUGIN_SLUG='layar-katalog-core'
THEME_SLUG='layar-katalog'

WP_PATH=''
DO_ACTIVATE=0
SKIP_RATE_LIMIT=0
SKIP_HTTP=0

PASS=0
FAIL=0
SKIP=0
FAILURES=()

usage() {
	# Print the comment block at the top of this file, without the leading '#'.
	awk 'NR <= 2 { next } /^#/ { sub(/^# ?/, ""); print; next } { exit }' "$0"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--path)
			WP_PATH="${2:-}"
			shift 2
			;;
		--path=*)
			WP_PATH="${1#--path=}"
			shift
			;;
		--activate)
			DO_ACTIVATE=1
			shift
			;;
		--skip-rate-limit)
			SKIP_RATE_LIMIT=1
			shift
			;;
		--skip-http)
			SKIP_HTTP=1
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			printf 'unknown argument: %s\n\n' "$1" >&2
			usage >&2
			exit 2
			;;
	esac
done

WP_ARGS=()
if [ -n "$WP_PATH" ]; then
	WP_ARGS+=(--path="$WP_PATH")
fi

run_wp() {
	command wp "$@" ${WP_ARGS[@]+"${WP_ARGS[@]}"}
}

ok() {
	PASS=$((PASS + 1))
	printf 'PASS  %s\n' "$1"
}

bad() {
	FAIL=$((FAIL + 1))
	FAILURES+=("$1")
	printf 'FAIL  %s\n' "$1"
	if [ -n "${2:-}" ]; then
		printf '        %s\n' "$2"
	fi
}

skip() {
	SKIP=$((SKIP + 1))
	printf 'SKIP  %s — %s\n' "$1" "$2"
}

section() {
	printf '\n--- %s ---\n' "$1"
}

expect() {
	if [ "$2" = "$3" ]; then
		ok "$1"
	else
		bad "$1" "expected [$2], got [$3]"
	fi
}

expect_contains() {
	case "$3" in
		*"$2"*) ok "$1" ;;
		*) bad "$1" "[$2] not found in: $(printf '%s' "$3" | head -c 400)" ;;
	esac
}

expect_not_contains() {
	case "$3" in
		*"$2"*) bad "$1" "[$2] unexpectedly present in: $(printf '%s' "$3" | head -c 400)" ;;
		*) ok "$1" ;;
	esac
}

# ---------------------------------------------------------------- preflight --

section 'preflight'

if ! command -v wp >/dev/null 2>&1; then
	bad 'WP-CLI is installed' 'wp not found in PATH; see https://wp-cli.org'
	printf '\n%d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
	exit 1
fi
ok 'WP-CLI is installed'

WP_VERSION="$(run_wp core version 2>/dev/null)"
if [ -z "$WP_VERSION" ]; then
	bad 'this directory is a WordPress install' 'wp core version returned nothing; pass --path'
	printf '\n%d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
	exit 1
fi
ok "WordPress ${WP_VERSION} is reachable"

PHP_VERSION="$(run_wp eval 'echo PHP_VERSION;' 2>/dev/null)"
expect 'the site runs PHP 8.2 or newer' '1' \
	"$(run_wp eval 'echo version_compare( PHP_VERSION, "8.2", ">=" ) ? "1" : "0";' 2>/dev/null)"
printf '      site PHP: %s\n' "$PHP_VERSION"

HOME_URL="$(run_wp option get home 2>/dev/null)"
PLUGIN_DIR="$(run_wp plugin path "$PLUGIN_SLUG" 2>/dev/null)"
THEME_DIR="$(run_wp theme path "$THEME_SLUG" 2>/dev/null)"

# ------------------------------------------------------- source integrity --

section 'shipped source parses on this server (F-15 regression)'

if ! command -v php >/dev/null 2>&1; then
	skip 'php -l over the shipped files' 'no php binary in PATH on this host'
else
	PARSE_BROKEN=0
	PARSE_COUNT=0
	if [ -z "$PLUGIN_DIR" ] || [ -z "$THEME_DIR" ]; then
		skip 'php -l over the shipped files' 'could not resolve the plugin or theme directory'
		PARSE_COUNT=-1
	fi
	while [ "$PARSE_COUNT" -ge 0 ] && IFS= read -r file; do
		PARSE_COUNT=$((PARSE_COUNT + 1))
		if ! php -l "$file" >/dev/null 2>&1; then
			PARSE_BROKEN=$((PARSE_BROKEN + 1))
			bad "parse: ${file}" "$(php -l "$file" 2>&1 | head -n 2 | tr '\n' ' ')"
		fi
	done < <(find "$PLUGIN_DIR" "$THEME_DIR" -name '*.php' -not -path '*/vendor/*' 2>/dev/null | sort)
	if [ "$PARSE_COUNT" -ge 0 ] && [ "$PARSE_BROKEN" -eq 0 ]; then
		ok "all ${PARSE_COUNT} shipped PHP files parse under PHP ${PHP_VERSION}"
	fi
fi

# ------------------------------------------------------------- activation --

section 'activation'

if [ "$DO_ACTIVATE" -eq 1 ]; then
	printf '      cycling activation (deactivate → activate → theme activate → flush rewrites)\n'
	ACTIVATE_OUTPUT="$(run_wp plugin deactivate "$PLUGIN_SLUG" 2>&1; run_wp plugin activate "$PLUGIN_SLUG" 2>&1; run_wp theme activate "$THEME_SLUG" 2>&1; run_wp rewrite flush --hard 2>&1)"
	printf '%s\n' "$ACTIVATE_OUTPUT" | sed 's/^/      /'
	if printf '%s' "$ACTIVATE_OUTPUT" | grep -qiE 'fatal error|parse error|warning:'; then
		bad 'activation produced no PHP diagnostics' "$(printf '%s' "$ACTIVATE_OUTPUT" | grep -iE 'fatal error|parse error|warning:' | head -n 3)"
	else
		ok 'activation produced no PHP diagnostics'
	fi
fi

ACTIVE_PLUGINS="$(run_wp plugin list --status=active --field=name 2>/dev/null | tr '\n' ',')"
case "$ACTIVE_PLUGINS" in
	*"${PLUGIN_SLUG},"*) ok "plugin ${PLUGIN_SLUG} is active" ;;
	*) bad "plugin ${PLUGIN_SLUG} is active" "active plugins: ${ACTIVE_PLUGINS:-none}" ;;
esac

ACTIVE_THEME="$(run_wp theme list --status=active --field=name 2>/dev/null | tr '\n' ',')"
case "$ACTIVE_THEME" in
	*"${THEME_SLUG},"*) ok "theme ${THEME_SLUG} is active" ;;
	*) bad "theme ${THEME_SLUG} is active" "active themes: ${ACTIVE_THEME:-none}" ;;
esac

# A block theme must not carry an index.php in its root: WordPress would treat
# it as a classic template and the whole FSE template hierarchy is bypassed.
if [ -n "$THEME_DIR" ] && [ -f "${THEME_DIR}/index.php" ]; then
	bad 'the block theme has no root index.php' "${THEME_DIR}/index.php exists and would hijack the template hierarchy"
else
	ok 'the block theme has no root index.php'
fi

# ------------------------------------------------------------------- schema --

section 'database schema'

PREFIX="$(run_wp eval 'global $wpdb; echo $wpdb->prefix;' 2>/dev/null)"
TABLES="$(run_wp eval 'global $wpdb; $found = $wpdb->get_col( "SHOW TABLES LIKE \"" . $wpdb->esc_like( $wpdb->prefix . "lkc_" ) . "%\"" ); echo implode( ",", (array) $found );' 2>/dev/null)"

for table in user_ratings push_subscriptions audit_log csp_reports; do
	expect_contains "table ${PREFIX}lkc_${table} exists" "lkc_${table}" "$TABLES"
done

PUSH_COLUMNS="$(run_wp eval 'global $wpdb; $t = $wpdb->prefix . "lkc_push_subscriptions"; $c = $wpdb->get_col( "SHOW COLUMNS FROM `$t`" ); echo implode( ",", (array) $c );' 2>/dev/null)"
expect_contains 'push table has failure_count (F-12)' 'failure_count' "$PUSH_COLUMNS"
expect_contains 'push table has last_failure_at (F-12)' 'last_failure_at' "$PUSH_COLUMNS"

expect 'engagement schema is at version 1.1' '1.1' \
	"$(run_wp option get lkc_engagement_db_version 2>/dev/null)"

# ------------------------------------------------------------------- routes --

section 'REST routes'

ROUTES="$(run_wp eval 'echo implode( "\n", array_keys( rest_get_server()->get_routes() ) );' 2>/dev/null)"

for route in '/lkc/v1/catalog/search' '/lkc/v1/ratings' '/lkc/v1/push/key' '/lkc/v1/push/subscribe' '/lkc/v1/push/unsubscribe' '/lkc/v1/csp-report'; do
	expect_contains "route ${route} is registered" "$route" "$ROUTES"
done

# -------------------------------------------------------------------- cron --

section 'scheduled events'

CRON_HOOKS="$(run_wp cron event list --fields=hook --format=csv 2>/dev/null | tail -n +2)"

for hook in lkc_process_episode_push_batch lkc_prune_security_logs lkc_prune_stale_push_subscriptions; do
	expect_contains "cron ${hook} is scheduled" "$hook" "$CRON_HOOKS"
done

# ------------------------------------------------------- secrets at rest ----

section 'VAPID key at rest'

VAPID_STATE="$(run_wp eval '$s = get_option( "lkc_push_options", array() ); $s = is_array( $s ) ? $s : array(); $k = isset( $s["private_key"] ) ? (string) $s["private_key"] : ""; if ( "" === $k ) { echo "absent"; } elseif ( 0 === strpos( $k, "lkc1:" ) ) { echo "encrypted"; } else { echo "plaintext"; }' 2>/dev/null)"

case "$VAPID_STATE" in
	encrypted)
		ok 'the stored VAPID private key is an encrypted envelope'
		;;
	plaintext)
		bad 'the stored VAPID private key is an encrypted envelope' 'still plaintext; open Pengaturan → Notifikasi Episode once so the migration runs'
		;;
	absent)
		skip 'the stored VAPID private key is an encrypted envelope' 'no key generated yet (push not configured)'
		;;
	*)
		bad 'the stored VAPID private key is an encrypted envelope' "unexpected state [$VAPID_STATE]"
		;;
esac

VAPID_USABLE="$(run_wp eval 'echo function_exists( "lkc_get_push_settings" ) ? ( "" !== lkc_get_push_settings()["private_key"] ? "usable" : "unreadable" ) : "missing";' 2>/dev/null)"
case "$VAPID_USABLE" in
	usable) ok 'the stored key decrypts with the current salts' ;;
	unreadable) bad 'the stored key decrypts with the current salts' 'salts in wp-config.php changed since the key was saved; regenerate it in Pengaturan → Notifikasi Episode' ;;
	missing) skip 'the stored key decrypts with the current salts' 'lkc_get_push_settings() is not available' ;;
	*) skip 'the stored key decrypts with the current salts' "unexpected state [$VAPID_USABLE]" ;;
esac

# ------------------------------------------------------- HTTP observations --

if [ "$SKIP_HTTP" -eq 1 ]; then
	section 'HTTP checks'
	skip 'security headers on a front-end response' '--skip-http was given'
elif ! command -v curl >/dev/null 2>&1; then
	section 'HTTP checks'
	skip 'security headers on a front-end response' 'curl is not installed on this host'
else
	section 'HTTP checks'

	DEBUG_LOG="$(run_wp eval 'if ( ! defined( "WP_DEBUG_LOG" ) || ! WP_DEBUG_LOG ) { echo ""; } elseif ( is_string( WP_DEBUG_LOG ) ) { echo WP_DEBUG_LOG; } else { echo ABSPATH . "wp-content/debug.log"; }' 2>/dev/null)"
	LOG_BEFORE=0
	if [ -n "$DEBUG_LOG" ] && [ -f "$DEBUG_LOG" ]; then
		LOG_BEFORE="$(wc -l <"$DEBUG_LOG" 2>/dev/null | tr -d ' ')"
	fi

	# A cache-busting query keeps a full-page cache from answering instead of WordPress.
	PROBE_URL="${HOME_URL%/}/?lkc_smoke=$$"
	HEADERS="$(curl -sS -D - -o /dev/null --max-time 25 "$PROBE_URL" 2>/dev/null)"
	if [ -z "$HEADERS" ]; then
		bad 'the front page answers over HTTP' "curl to ${PROBE_URL} returned nothing"
	else
		ok "the front page answers over HTTP (${PROBE_URL})"

		expect_contains 'X-Content-Type-Options: nosniff is sent' 'X-Content-Type-Options: nosniff' "$HEADERS"
		expect_contains 'Referrer-Policy is sent' 'Referrer-Policy:' "$HEADERS"
		expect_contains 'X-Frame-Options is sent' 'X-Frame-Options:' "$HEADERS"
		expect_contains 'Permissions-Policy is sent' 'Permissions-Policy:' "$HEADERS"

		case "$HOME_URL" in
			https://*)
				expect_contains 'Strict-Transport-Security is sent over HTTPS' 'Strict-Transport-Security:' "$HEADERS"
				;;
			*)
				skip 'Strict-Transport-Security is sent over HTTPS' 'the site URL is not https'
				;;
		esac

		if printf '%s' "$HEADERS" | grep -qi '^Content-Security-Policy:'; then
			ok 'CSP is being enforced'
		elif printf '%s' "$HEADERS" | grep -qi '^Content-Security-Policy-Report-Only:'; then
			ok 'CSP is present in report-only mode (expected before the allowlist is complete)'
		else
			bad 'a Content-Security-Policy header is present' 'neither Content-Security-Policy nor -Report-Only was sent'
		fi
	fi

	section 'vendor/ is not web-reachable (F-09)'

	PLUGIN_URL="$(run_wp eval 'echo plugins_url( "layar-katalog-core" );' 2>/dev/null)"
	if [ -z "$PLUGIN_URL" ]; then
		skip 'vendor/composer/installed.json is blocked' 'could not resolve plugins_url()'
	else
		for target in '/vendor/composer/installed.json' '/vendor/autoload.php' '/includes/security.php'; do
			CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "${PLUGIN_URL}${target}" 2>/dev/null)"
			if [ "$CODE" = '200' ]; then
				bad "${target} is not served over HTTP" "got 200 — on nginx add the location block documented in vendor/.htaccess"
			elif [ -z "$CODE" ] || [ "$CODE" = '000' ]; then
				skip "${target} is not served over HTTP" 'no HTTP response (curl failed)'
			else
				ok "${target} is not served over HTTP (HTTP ${CODE})"
			fi
		done
	fi

	if [ "$SKIP_RATE_LIMIT" -eq 1 ]; then
		skip 'the REST rate limiter answers 429' '--skip-rate-limit was given'
	else
		section 'REST rate limiter'

		SUBSCRIBE_URL="$(run_wp eval 'echo esc_url_raw( rest_url( "lkc/v1/push/subscribe" ) );' 2>/dev/null)"
		FIRST_CODE=''
		SAW_429=0
		for attempt in 1 2 3 4 5 6 7 8 9 10 11 12; do
			CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 -X POST \
				-H 'Content-Type: application/json' --data '{}' "$SUBSCRIBE_URL" 2>/dev/null)"
			if [ "$attempt" -eq 1 ]; then
				FIRST_CODE="$CODE"
			fi
			if [ "$CODE" = '429' ]; then
				SAW_429=1
			fi
		done

		if [ "$FIRST_CODE" = '429' ]; then
			bad 'the first write request is not limited' 'the limiter was already exhausted; wait a minute and re-run'
		elif [ "$SAW_429" -eq 1 ]; then
			ok "the write bucket returns 429 within 12 requests (first was ${FIRST_CODE})"
		else
			bad 'the write bucket returns 429 within 12 requests' "no 429 seen; first response was ${FIRST_CODE}"
		fi
	fi

	if [ -n "$DEBUG_LOG" ] && [ -f "$DEBUG_LOG" ]; then
		section 'debug.log'
		LOG_AFTER="$(wc -l <"$DEBUG_LOG" 2>/dev/null | tr -d ' ')"
		NEW_LINES=$((LOG_AFTER - LOG_BEFORE))
		if [ "$NEW_LINES" -le 0 ]; then
			ok "no new debug.log entries during this run (${DEBUG_LOG})"
		else
			NEW_CONTENT="$(tail -n "$NEW_LINES" "$DEBUG_LOG" 2>/dev/null)"
			if printf '%s' "$NEW_CONTENT" | grep -qiE 'layar-katalog|lkc_|lk_'; then
				bad "${NEW_LINES} new debug.log entries do not mention this plugin" \
					"$(printf '%s' "$NEW_CONTENT" | grep -iE 'layar-katalog|lkc_|lk_' | head -n 3)"
			else
				ok "${NEW_LINES} new debug.log entries, none from this plugin"
			fi
		fi
		printf '      note: debug.log must not be web-reachable; check %s\n' "$DEBUG_LOG"
	fi
fi

# ------------------------------------------------------------------ summary --

printf '\n=================================\n'
printf '%d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"

if [ "$FAIL" -gt 0 ]; then
	printf '\nFailed checks:\n'
	for item in ${FAILURES[@]+"${FAILURES[@]}"}; do
		printf '  - %s\n' "$item"
	done
	exit 1
fi

exit 0
