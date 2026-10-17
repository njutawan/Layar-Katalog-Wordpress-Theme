#!/usr/bin/env bash
#
# Runs tests/wp/smoke-activate.sh against a simulated WordPress install.
#
# smoke-activate.sh is the script an operator runs on staging before going
# live. It had never been executed against anything: its guards were covered by
# guards.test.sh, but its actual checks - the part that decides whether a site
# is healthy - had only ever been read. A check that can never pass, a wp-cli
# field name that does not exist, or a grep that matches nothing would all have
# looked fine right up to the moment somebody relied on the output on staging.
#
# So this file builds a WordPress that is not there: a fake `wp` that answers
# every query the script makes, a fake `curl` that returns the headers the
# plugin sends, a fake `php -l`, a plugin tree, and a debug.log. Every value
# comes from a SIM_* variable with a healthy default, so each test degrades
# exactly one thing and asserts the script notices that thing and nothing else.
#
# The stubs encode what WP-CLI and curl are documented to output. They prove the
# script's logic - parsing, ordering, thresholds, which failure it reports - and
# they cannot prove that a real site behaves like the simulation. That last gap
# is what running it on staging is for.
#
# Usage: bash tests/wp/simulated-install.test.sh

set -uo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"

PASS=0
FAIL=0

ok() { PASS=$((PASS + 1)); printf 'PASS  %s\n' "$1"; }
bad() {
	FAIL=$((FAIL + 1))
	printf 'FAIL  %s\n' "$1"
	if [ -n "${2:-}" ]; then printf '        %s\n' "$2"; fi
}
section() { printf '\n--- %s ---\n' "$1"; }

work="$(mktemp -d "${TMPDIR:-/tmp}/lkc-sim.XXXXXX")"
cleanup() { rm -rf "$work"; }
trap cleanup EXIT

bin="$work/bin"
install_root="$work/install"
plugin_dir="$install_root/wp-content/plugins/layar-katalog-core"
theme_dir="$install_root/wp-content/themes/layar-katalog"
debug_log="$install_root/wp-content/debug.log"

mkdir -p "$bin" "$plugin_dir/includes" "$theme_dir/templates"

# A plugin and theme tree with the real shipped file names, so the parse check
# counts something plausible and the block-theme index.php check has a directory
# to look at.
for file in layar-katalog-core.php includes/security.php includes/audit.php includes/engagement.php includes/web-push.php index.php; do
	printf '<?php\n/* simulated %s */\n' "$file" >"$plugin_dir/$file"
done
for file in functions.php style.css templates/front-page.html theme.json; do
	printf '/* simulated %s */\n' "$file" >"$theme_dir/$file"
done
printf 'PHP Notice: unrelated thing in /srv/www/wp-includes/class-wp-query.php\n' >"$debug_log"

WP_LOG="$work/wp-invocations.log"
CURL_LOG="$work/curl-invocations.log"
RATE_COUNTER="$work/rate-counter"
: >"$WP_LOG"
: >"$CURL_LOG"

# --------------------------------------------------------------------- stubs --

cat >"$bin/wp" <<'WPSTUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$WP_LOG"

answer_eval() {
	local code="$1"
	case "$code" in
		*'SHOW TABLES'*) printf '%s' "${SIM_TABLES:-wp_lkc_user_ratings,wp_lkc_push_subscriptions,wp_lkc_audit_log,wp_lkc_csp_reports}" ;;
		*'SHOW COLUMNS'*) printf '%s' "${SIM_PUSH_COLUMNS:-id,user_id,endpoint,auth_key,p256dh_key,failure_count,last_failure_at,created_at}" ;;
		*'rest_get_server'*) printf '%b' "${SIM_ROUTES:-/lkc/v1/catalog/search\n/lkc/v1/ratings/(?P<title_id>\\d+)\n/lkc/v1/push/key\n/lkc/v1/push/subscribe\n/lkc/v1/push/unsubscribe\n/lkc/v1/csp-report\n}" ;;
		*'is_array( $o )'*)
			if [ -n "${SIM_NO_LIVE_KEY:-}" ]; then printf ''; else printf '%s' "${SIM_LIVE_PRIVATE:-lkc1:v1:simulated:ciphertext}"; fi
			;;
		*'lkc_push_options'*) printf '%s' "${SIM_VAPID_STATE:-encrypted}" ;;
		*'"usable"'*) printf '%s' "${SIM_VAPID_USABLE:-usable}" ;;
		*'lkc_get_push_settings'*) printf '%s' "${SIM_DECRYPTABLE:-yes}" ;;
		*'get_stylesheet'*) printf '%s' "${SIM_STYLESHEET:-layar-katalog}" ;;
		*'WP_DEBUG_LOG'*) printf '%s' "${SIM_DEBUG_LOG:-}" ;;
		*'plugins_url'*) printf '%s' "${SIM_PLUGIN_URL:-https://sim.test/wp-content/plugins/layar-katalog-core}" ;;
		*'rest_url'*) printf '%s' "${SIM_SUBSCRIBE_URL:-https://sim.test/wp-json/lkc/v1/push/subscribe}" ;;
		*'version_compare'*) printf '%s' "${SIM_PHP_MEETS_MINIMUM:-1}" ;;
		*'PHP_VERSION'*) printf '%s' "${SIM_PHP_VERSION:-8.2.33}" ;;
		*'$wpdb->prefix'*) printf '%s' "${SIM_TABLE_PREFIX:-wp_}" ;;
		*) printf '%s' "${SIM_EVAL_DEFAULT:-}" ;;
	esac
}

case "${1:-}" in
	eval) answer_eval "${2:-}" ;;
	core)
		if [ -n "${SIM_NO_WORDPRESS:-}" ]; then printf '\n'; else printf '%s\n' "${SIM_WP_VERSION:-6.8.2}"; fi
		;;
	option)
		case "${3:-}" in
			home) printf '%s\n' "${SIM_HOME_URL:-https://sim.test}" ;;
			lkc_engagement_db_version) printf '%s\n' "${SIM_ENGAGEMENT_DB_VERSION:-1.1}" ;;
			lkc_security_db_version) printf '%s\n' "${SIM_SECURITY_DB_VERSION:-1.0}" ;;
			*) printf '\n' ;;
		esac
		;;
	plugin)
		case "${2:-}" in
			path) printf '%s\n' "${SIM_PLUGIN_DIR:-}" ;;
			list) printf '%b' "${SIM_ACTIVE_PLUGINS:-layar-katalog-core\nakismet\n}" ;;
			activate) printf '%b' "${SIM_ACTIVATE_OUTPUT:-Success: Activated 1 of 1 plugins.\n}" ;;
			deactivate) printf '%b' "${SIM_DEACTIVATE_OUTPUT:-Success: Deactivated 1 of 1 plugins.\n}" ;;
			*) printf '\n' ;;
		esac
		;;
	theme)
		case "${2:-}" in
			path) printf '%s\n' "${SIM_THEME_DIR:-}" ;;
			list) printf '%b' "${SIM_ACTIVE_THEMES:-layar-katalog\n}" ;;
			activate) printf '%b' "${SIM_THEME_ACTIVATE_OUTPUT:-Success: Switched to 'layar-katalog' theme.\n}" ;;
			*) printf '\n' ;;
		esac
		;;
	rewrite) printf '%b' "${SIM_REWRITE_OUTPUT:-Success: Rewrite rules flushed.\n}" ;;
	cron) printf '%b' "hook\n${SIM_CRON:-lkc_process_episode_push_batch\nlkc_prune_security_logs\nlkc_prune_stale_push_subscriptions\n}" ;;
	db)
		case "${2:-}" in
			export)
				target="${3:-/dev/null}"
				{
					printf -- '-- Simulated mysqldump of %s\n' "${SIM_SOURCE_DB:-live_catalog_db}"
					printf -- '-- %s rows, generated by the simulation\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
					i=0
					while [ "$i" -lt "${SIM_DUMP_LINES:-40}" ]; do
						printf 'INSERT INTO wp_posts VALUES (%d,1,2026-01-01,publish,lk_title);\n' "$i"
						i=$((i + 1))
					done
				} >"$target"
				printf 'Success: Exported to %s\n' "$target"
				;;
			query) "${LKC_ANSWER:-/bin/echo}" "${3:-}" ;;
			*) printf '\n' ;;
		esac
		;;
	config)
		case "${2:-}" in
			get)
				case "${3:-}" in
					DB_NAME) printf '%s\n' "${SIM_SOURCE_DB:-live_catalog_db}" ;;
					DB_USER) printf 'stub_user\n' ;;
					DB_PASSWORD) printf 'stub_password\n' ;;
					DB_HOST) printf 'localhost\n' ;;
					*) printf '\n' ;;
				esac
				;;
			path) printf '%s\n' "${SIM_LIVE_CONFIG:-}" ;;
			*) printf '\n' ;;
		esac
		;;
	*) printf '\n' ;;
esac
WPSTUB

# Answers the three curl shapes the script uses: a header dump, and two kinds of
# status-code probe. The rate-limit probe counts its calls so the bucket can be
# made to exhaust at a chosen request, exactly like the real limiter.
cat >"$bin/curl" <<'CURLSTUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$CURL_LOG"

url=""
want_headers=0
want_code=0
is_post=0
for arg in "$@"; do
	case "$arg" in
		-D) want_headers=1 ;;
		-w) want_code=1 ;;
		-X) is_post=1 ;;
		http*|https*) url="$arg" ;;
	esac
done

if [ -n "${SIM_DEBUG_NOISE:-}" ] && [ "$is_post" -eq 1 ]; then
	printf 'PHP Notice: lkc_save_push_subscription rejected an endpoint in %s\n' "$0" >>"${SIM_DEBUG_LOG:-/dev/null}"
fi

if [ "$want_headers" -eq 1 ]; then
	printf 'HTTP/1.1 200 OK\n'
	printf 'Content-Type: text/html; charset=UTF-8\n'
	printf 'X-Content-Type-Options: nosniff\n'
	printf 'Referrer-Policy: strict-origin-when-cross-origin\n'
	printf 'X-Frame-Options: SAMEORIGIN\n'
	printf 'Permissions-Policy: geolocation=(), microphone=(), camera=()\n'
	if [ -z "${SIM_NO_HSTS:-}" ]; then
		printf 'Strict-Transport-Security: max-age=31536000; includeSubDomains\n'
	fi
	case "${SIM_CSP_MODE:-report-only}" in
		enforce) printf "Content-Security-Policy: %s\n" "${SIM_CSP_VALUE:-default-src 'self'; object-src 'none'}" ;;
		report-only) printf "Content-Security-Policy-Report-Only: %s\n" "${SIM_CSP_VALUE:-default-src 'self'; object-src 'none'}" ;;
		off) : ;;
	esac
	printf '\n'
	exit 0
fi

if [ "$want_code" -eq 1 ]; then
	case "$url" in
		*/vendor/*|*/includes/security.php) printf '%s' "${SIM_VENDOR_CODE:-403}" ;;
		*)
			count=0
			if [ -f "$RATE_COUNTER" ]; then count="$(cat "$RATE_COUNTER")"; fi
			count=$((count + 1))
			printf '%s' "$count" >"$RATE_COUNTER"
			threshold="${SIM_RATE_LIMIT_AFTER:-11}"
			if [ "$threshold" -gt 0 ] && [ "$count" -ge "$threshold" ]; then
				printf '429'
			else
				printf '%s' "${SIM_WRITE_CODE:-400}"
			fi
			;;
	esac
	exit 0
fi
exit 0
CURLSTUB

# A php that approves every file unless it is the one this test planted.
cat >"$bin/php" <<'PHPSTUB'
#!/usr/bin/env bash
for arg in "$@"; do
	if [ -n "${SIM_PHP_FAIL_FILE:-}" ] && [ "$arg" = "${SIM_PHP_FAIL_FILE}" ]; then
		printf '\nParse error: syntax error, unexpected token "=" in %s on line 3\n' "$arg" >&2
		exit 255
	fi
done
printf 'No syntax errors detected in %s\n' "${@: -1}"
exit 0
PHPSTUB

chmod +x "$bin/wp" "$bin/curl" "$bin/php"

# The same stubs without php, to exercise the script's "no php binary" branch.
mkdir -p "$work/bin-nophp"
cp "$bin/wp" "$bin/curl" "$work/bin-nophp/"
chmod +x "$work/bin-nophp/wp" "$work/bin-nophp/curl"

SMOKE="$repo/tests/wp/smoke-activate.sh"

# Extra flags for the script under test; set per test, reset afterwards.
SMOKE_ARGS=()

# run_sim [BIN_DIR] [SIM_NAME=VALUE ...]
# Runs the script against the simulation and leaves the output in $OUTPUT,
# the exit status in $STATUS, and the failure list in $FAILURES_TEXT.
run_sim() {
	local sim_bin="$1"
	shift
	: >"$RATE_COUNTER"
	: >"$WP_LOG"
	: >"$CURL_LOG"
	printf 'PHP Notice: unrelated thing in /srv/www/wp-includes/class-wp-query.php\n' >"$debug_log"
	OUTPUT="$(env -u SIM_PHP_FAIL_FILE -u SIM_TABLES -u SIM_CRON -u SIM_ROUTES -u SIM_VAPID_STATE \
		-u SIM_VAPID_USABLE -u SIM_CSP_MODE -u SIM_VENDOR_CODE -u SIM_RATE_LIMIT_AFTER \
		-u SIM_ACTIVE_PLUGINS -u SIM_ACTIVE_THEMES -u SIM_WP_VERSION -u SIM_DEBUG_NOISE \
		-u SIM_NO_HSTS -u SIM_ACTIVATE_OUTPUT -u SIM_ENGAGEMENT_DB_VERSION -u SIM_PUSH_COLUMNS \
		PATH="$sim_bin:$PATH" \
		SIM_PLUGIN_DIR="$plugin_dir" SIM_THEME_DIR="$theme_dir" SIM_DEBUG_LOG="$debug_log" \
		WP_LOG="$WP_LOG" CURL_LOG="$CURL_LOG" RATE_COUNTER="$RATE_COUNTER" \
		"$@" \
		bash "$SMOKE" --path="$install_root" ${SMOKE_ARGS[@]+"${SMOKE_ARGS[@]}"} 2>&1)"
	STATUS=$?
	FAILURES_TEXT="$(printf '%s\n' "$OUTPUT" | sed -n '/^Failed checks:/,$p')"
}

# assert_failed LABEL — the run must have failed, and named this check.
assert_failed() {
	local label="$1"
	local got
	got="$(printf '%s\n' "$OUTPUT" | grep -F "$label" | grep -c '^FAIL')"
	if [ "$STATUS" -ne 0 ] && [ "${got:-0}" -ge 1 ]; then
		ok "reported: $label"
	else
		bad "reported: $label" "exit $STATUS; $(printf '%s\n' "$OUTPUT" | grep -E '^(FAIL|SKIP)' | head -4 | tr '\n' '|')"
	fi
}

# assert_clean LABEL — this check passed, whatever else the run found. Used to
# prove that a degradation is blamed on one thing and does not take the rest of
# the report down with it.
assert_clean() {
	local label="$1"
	if printf '%s\n' "$OUTPUT" | grep -qF "PASS  ${label}" && ! printf '%s\n' "$OUTPUT" | grep -qF "FAIL  ${label}"; then
		ok "still passes: $label"
	else
		bad "still passes: $label" "$(printf '%s\n' "$OUTPUT" | grep -F "$label" | head -2 | tr '\n' '|')"
	fi
}

# assert_exit_zero WHY — the whole run must have been green.
assert_exit_zero() {
	if [ "$STATUS" -eq 0 ]; then
		ok "the run is green: $1"
	else
		bad "the run is green: $1" "exit $STATUS; $(printf '%s\n' "$OUTPUT" | grep '^FAIL' | head -3 | tr '\n' '|')"
	fi
}

# assert_skipped LABEL — the run must have passed with this check skipped.
assert_skipped() {
	local label="$1"
	if [ "$STATUS" -eq 0 ] && printf '%s\n' "$OUTPUT" | grep -qF "SKIP  ${label}"; then
		ok "skipped, not failed: $label"
	else
		bad "skipped, not failed: $label" "exit $STATUS; $(printf '%s\n' "$OUTPUT" | grep -F "$label" | head -2 | tr '\n' '|')"
	fi
}

# assert_none_failed LABEL — this check was not among the failures. Some checks
# only ever print a label when they fail, so "still passes" cannot be used here.
assert_none_failed() {
	local label="$1"
	if printf '%s\n' "$OUTPUT" | grep -F "$label" | grep -q '^FAIL'; then
		bad "not blamed: $label" "$(printf '%s\n' "$OUTPUT" | grep -F "$label" | head -2 | tr '\n' '|')"
	else
		ok "not blamed: $label"
	fi
}

# assert_count_failed N SUBSTR — exactly N failed checks contain SUBSTR. This is
# how a test says "the script blamed one thing, not everything".
assert_count_failed() {
	local want="$1" label="$2"
	local got
	got="$(printf '%s\n' "$OUTPUT" | grep -F "$label" | grep -c '^FAIL')"
	if [ "$STATUS" -ne 0 ] && [ "${got:-0}" -eq "$want" ]; then
		ok "exactly $want check(s) matching [$label] failed"
	else
		bad "exactly $want check(s) matching [$label] failed" "found ${got:-0}; exit $STATUS"
	fi
}

# ------------------------------------------------------------------ healthy --

section 'a healthy simulated install passes'

run_sim "$bin"
summary="$(printf '%s\n' "$OUTPUT" | grep -E '^[0-9]+ passed, [0-9]+ failed, [0-9]+ skipped$')"

if [ "$STATUS" -eq 0 ]; then ok 'the script exits 0 on a healthy install'; else bad 'the script exits 0 on a healthy install' "exit $STATUS; $summary"; fi
if printf '%s' "$summary" | grep -q ', 0 failed,'; then ok 'nothing is reported as failed'; else bad 'nothing is reported as failed' "$summary"; fi

sim_passes="$(printf '%s' "$summary" | cut -d' ' -f1)"
if [ "${sim_passes:-0}" -ge 30 ]; then
	ok "the checks really ran (${sim_passes} passed, not a wall of skips)"
else
	bad "the checks really ran (${sim_passes} passed)" "$OUTPUT"
fi

skips="$(printf '%s' "$summary" | awk '{print $(NF-1)}')"
if [ "${skips:-9}" -le 1 ]; then ok 'at most one check was skipped'; else bad 'at most one check was skipped' "$skips skips: $(printf '%s\n' "$OUTPUT" | grep '^SKIP' | head -3)"; fi

for label in \
	'table wp_lkc_user_ratings exists' \
	'table wp_lkc_csp_reports exists' \
	'push table has failure_count (F-12)' \
	'engagement schema is at version 1.1' \
	'route /lkc/v1/push/subscribe is registered' \
	'cron lkc_prune_security_logs is scheduled' \
	'the stored VAPID private key is an encrypted envelope' \
	'the stored key decrypts with the current salts' \
	'X-Content-Type-Options: nosniff is sent' \
	'Strict-Transport-Security is sent over HTTPS' \
	'CSP is present in report-only mode (expected before the allowlist is complete)' \
	'/vendor/composer/installed.json is not served over HTTP (HTTP 403)' \
	'the write bucket returns 429 within 12 requests' \
	'no new debug.log entries during this run' \
	'plugin layar-katalog-core is active' \
	'theme layar-katalog is active' \
	'the block theme has no root index.php'
do
	if printf '%s\n' "$OUTPUT" | grep -qF "$label"; then
		ok "checked: $label"
	else
		bad "checked: $label" 'this check never reported anything'
	fi
done

parse_count="$(find "$plugin_dir" "$theme_dir" -name '*.php' -not -path '*/vendor/*' | wc -l | tr -d ' ')"
if printf '%s\n' "$OUTPUT" | grep -q "all ${parse_count} shipped PHP files parse under PHP 8.2.33"; then
	ok 'the F-15 parse check counted the shipped files'
else
	bad 'the F-15 parse check counted the shipped files' "$(printf '%s\n' "$OUTPUT" | grep -i 'parse' | head -3)"
fi

if grep -q 'cron event list --fields=hook --format=csv' "$WP_LOG"; then
	ok 'cron is read with a field list that wp-cli actually supports'
else
	bad 'cron is read with a field list that wp-cli actually supports' "$(cat "$WP_LOG")"
fi

if grep -q 'plugin list --status=active --field=name' "$WP_LOG"; then
	ok 'active plugins are read with --field=name, not a status field that does not exist'
else
	bad 'active plugins are read with --field=name' "$(cat "$WP_LOG")"
fi

if grep -q 'db import' "$WP_LOG"; then
	bad 'the script never imports a database' 'it called wp db import'
else
	ok 'the script never imports a database'
fi

# ------------------------------------------------------- degraded installs --
#
# Each case below changes exactly one thing about the simulated site and asserts
# that the script notices that thing - and, where it matters, that it does not
# blame anything else. The labels are the script's own, so a renamed check fails
# here instead of silently passing on staging.

section 'a missing table is reported'

run_sim "$bin" SIM_TABLES='wp_lkc_user_ratings,wp_lkc_push_subscriptions,wp_lkc_audit_log'
assert_failed 'table wp_lkc_csp_reports exists'
assert_count_failed 1 'table wp_lkc_'

section 'a missing cron event is reported'

run_sim "$bin" SIM_CRON='lkc_process_episode_push_batch
lkc_prune_stale_push_subscriptions'
assert_failed 'cron lkc_prune_security_logs is scheduled'
assert_count_failed 1 'cron lkc_'

section 'missing REST routes are all reported'

run_sim "$bin" SIM_ROUTES='/lkc/v1/catalog/search
/lkc/v1/push/key'
assert_count_failed 4 'route /lkc/v1'

section 'the VAPID key at rest'

run_sim "$bin" SIM_VAPID_STATE='plaintext'
assert_failed 'the stored VAPID private key is an encrypted envelope'

run_sim "$bin" SIM_VAPID_STATE='absent' SIM_VAPID_USABLE='missing'
assert_skipped 'the stored VAPID private key is an encrypted envelope'
assert_skipped 'the stored key decrypts with the current salts'

run_sim "$bin" SIM_VAPID_USABLE='unreadable'
assert_failed 'the stored key decrypts with the current salts'
assert_clean 'the stored VAPID private key is an encrypted envelope'

section 'the security headers'

run_sim "$bin" SIM_CSP_MODE='off'
assert_failed 'a Content-Security-Policy header is present'
assert_clean 'X-Content-Type-Options: nosniff is sent'

run_sim "$bin" SIM_CSP_MODE='enforce'
assert_clean 'CSP is being enforced'
assert_exit_zero 'enforce mode is a healthy configuration'

run_sim "$bin" SIM_NO_HSTS=1
assert_failed 'Strict-Transport-Security is sent over HTTPS'

run_sim "$bin" SIM_HOME_URL='http://sim.test' SIM_NO_HSTS=1
assert_skipped 'Strict-Transport-Security is sent over HTTPS'

section 'vendor/ reachability (F-09)'

run_sim "$bin" SIM_VENDOR_CODE='200'
assert_count_failed 3 'is not served over HTTP'

run_sim "$bin" SIM_VENDOR_CODE='404'
assert_clean '/vendor/composer/installed.json is not served over HTTP (HTTP 404)'
assert_exit_zero 'a 404 on vendor/ is as good as a 403'

section 'the rate limiter'

run_sim "$bin" SIM_RATE_LIMIT_AFTER='0'
assert_failed 'the write bucket returns 429 within 12 requests'

run_sim "$bin" SIM_RATE_LIMIT_AFTER='1'
assert_failed 'the first write request is not limited'

run_sim "$bin" SIM_RATE_LIMIT_AFTER='12'
assert_clean 'the write bucket returns 429 within 12 requests'
assert_exit_zero 'a limiter that trips on the last probe is healthy'

run_sim "$bin" SIM_RATE_LIMIT_AFTER='13'
assert_failed 'the write bucket returns 429 within 12 requests'

section 'activation state'

run_sim "$bin" SIM_ACTIVE_PLUGINS='akismet
hello-dolly'
assert_failed 'plugin layar-katalog-core is active'
assert_clean 'theme layar-katalog is active'

run_sim "$bin" SIM_ACTIVE_THEMES='twentytwentyfive'
assert_failed 'theme layar-katalog is active'
assert_clean 'plugin layar-katalog-core is active'

printf '<?php\n/* a root index.php hijacks the FSE template hierarchy */\n' >"$theme_dir/index.php"
run_sim "$bin"
assert_failed 'the block theme has no root index.php'
rm -f "$theme_dir/index.php"

run_sim "$bin" SIM_NO_WORDPRESS=1
assert_failed 'this directory is a WordPress install'

run_sim "$bin" SIM_PHP_MEETS_MINIMUM=0
assert_failed 'the site runs PHP 8.2 or newer'

run_sim "$bin" SIM_ENGAGEMENT_DB_VERSION='1.0'
assert_failed 'engagement schema is at version 1.1'

run_sim "$bin" SIM_PUSH_COLUMNS='id,user_id,endpoint,auth_key,p256dh_key,created_at'
assert_failed 'push table has failure_count (F-12)'
assert_failed 'push table has last_failure_at (F-12)'

section 'the parse check and the activation cycle'

broken="$plugin_dir/includes/broken.php"
printf '<?php\n$episode_meta = array(\n' >"$broken"
run_sim "$bin" SIM_PHP_FAIL_FILE="$broken"
assert_failed "parse: $broken"
rm -f "$broken"

run_sim "$bin"
assert_clean 'all 7 shipped PHP files parse under PHP 8.2.33'
assert_exit_zero 'a site whose sources all parse'

run_sim "$work/bin-nophp"
assert_skipped 'php -l over the shipped files'

SMOKE_ARGS=(--activate)
run_sim "$bin" SIM_ACTIVATE_OUTPUT='PHP Fatal error: Uncaught Error: Call to undefined function lkc_register_meta() in /srv/www/wp-content/plugins/layar-katalog-core/layar-katalog-core.php on line 272'
assert_failed 'activation produced no PHP diagnostics'
SMOKE_ARGS=()

section 'debug.log'

run_sim "$bin" SIM_DEBUG_NOISE=1
assert_failed 'do not mention this plugin'

run_sim "$bin" SIM_DEBUG_LOG=''
if [ "$STATUS" -eq 0 ] && ! printf '%s\n' "$OUTPUT" | grep -q 'debug.log'; then
	ok 'a site with WP_DEBUG_LOG off simply does not report on it'
else
	bad 'a site with WP_DEBUG_LOG off simply does not report on it' "exit $STATUS; $(printf '%s\n' "$OUTPUT" | grep -c 'debug.log') mentions"
fi
section 'the flags'

run_sim "$bin"
plain="$STATUS"
run_sim "$bin"
if [ "$STATUS" -eq "$plain" ] && [ "$STATUS" -eq 0 ]; then
	ok 'the simulation is reproducible across repeated runs'
else
	bad 'the simulation is reproducible across repeated runs' "$plain then $STATUS"
fi

SMOKE_ARGS=(--activate)
run_sim "$bin"
if [ "$STATUS" -eq 0 ] && grep -q 'plugin deactivate layar-katalog-core' "$WP_LOG" && grep -q 'rewrite flush --hard' "$WP_LOG"; then
	ok '--activate cycles the plugin and flushes rewrites'
else
	bad '--activate cycles the plugin and flushes rewrites' "exit $STATUS; $(tr '\n' '|' <"$WP_LOG")"
fi
if printf '%s\n' "$OUTPUT" | grep -q 'activation produced no PHP diagnostics'; then
	ok 'a clean activation cycle is reported as clean'
else
	bad 'a clean activation cycle is reported as clean' "$FAILURES_TEXT"
fi
SMOKE_ARGS=()

SMOKE_ARGS=(--skip-http)
run_sim "$bin" SIM_VENDOR_CODE='200'
if [ "$STATUS" -eq 0 ] && printf '%s\n' "$OUTPUT" | grep -q 'SKIP.*--skip-http'; then
	ok '--skip-http bypasses every curl check, even a reachable vendor/'
else
	bad '--skip-http bypasses every curl check' "exit $STATUS; $FAILURES_TEXT"
fi
SMOKE_ARGS=()

SMOKE_ARGS=(--skip-rate-limit)
run_sim "$bin" SIM_RATE_LIMIT_AFTER='0'
if [ "$STATUS" -eq 0 ] && printf '%s\n' "$OUTPUT" | grep -q 'SKIP.*--skip-rate-limit'; then
	ok '--skip-rate-limit bypasses a limiter that never answers 429'
else
	bad '--skip-rate-limit bypasses a limiter that never answers 429' "exit $STATUS; $FAILURES_TEXT"
fi
SMOKE_ARGS=()

# ------------------------------------------------ a simulated restore drill --
#
# The same treatment for rehearse-restore.sh: a fake mysql client that answers
# the inventory queries, a fake `wp db export` that writes a plausible dump, and
# two wp-config.php files whose salts can be made to disagree. What this proves
# is the drill's own logic - that it really checks the inventory, really
# compares the salts, really cleans up, and really never points mysql at the
# live database.

RESTORE="$repo/tests/wp/rehearse-restore.sh"
MYSQL_LOG="$work/mysql-invocations.log"
live_config="$work/wp-config.php"
backup_config="$work/wp-config-backup.php"
: >"$MYSQL_LOG"

write_config() {
	local target="$1" count="${2:-8}" n=0
	: >"$target"
	printf '<?php\n/* Simulated wp-config.php */\n' >"$target"
	for salt in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
		n=$((n + 1))
		if [ "$n" -gt "$count" ]; then break; fi
		printf "define( '%s', 'simulated-salt-value-%s' );\n" "$salt" "$salt" >>"$target"
	done
}
write_config "$live_config" 8
write_config "$backup_config" 8

# Answers every COUNT(*) the drill asks, from SIM_* values with healthy defaults.
cat >"$bin/lkc-answer-query" <<'ANSWERSTUB'
#!/usr/bin/env bash
q="${1:-}"
case "$q" in
	*'SELECT 1'*) printf '1\n' ;;
	*'information_schema.TABLES'*) printf '%s\n' "${SIM_RESTORED_TABLES:-4}" ;;
	*'CHARACTER_SET_NAME'*) printf '%s\n' "${SIM_CHARSET:-utf8mb4}" ;;
	*'COLLATION_NAME'*) printf '%s\n' "${SIM_COLLATION:-utf8mb4_unicode_520_ci}" ;;
	*'option_value FROM'*'lkc_push_options'*)
		printf 'a:1:{s:11:"private_key";s:30:"%s";}\n' "${SIM_RESTORED_ENVELOPE:-lkc1:v1:simulated:ciphertext}"
		;;
	*'_lkc_user_ratings'*) printf '%s\n' "${SIM_ROWS_RATINGS:-7}" ;;
	*'_lkc_push_subscriptions'*) printf '%s\n' "${SIM_ROWS_PUSH:-3}" ;;
	*'_lkc_audit_log'*) printf '%s\n' "${SIM_ROWS_AUDIT:-41}" ;;
	*'_lkc_csp_reports'*) printf '%s\n' "${SIM_ROWS_CSP:-12}" ;;
	*"post_type = 'lk_title'"*) printf '%s\n' "${SIM_TITLES:-18}" ;;
	*"post_type = 'lk_episode'"*) printf '%s\n' "${SIM_EPISODES:-244}" ;;
	*'video'*) printf '%s\n' "${SIM_VIDEO_META:-244}" ;;
	*"option_name = 'lkc_push_options'"*) printf '%s\n' "${SIM_PUSH_OPTION:-1}" ;;
	*"option_name = 'layarkatalog_ads'"*) printf '%s\n' "${SIM_ADS_OPTION:-1}" ;;
	*"option_name = 'cron'"*) printf '%s\n' "${SIM_CRON_OPTION:-1}" ;;
	*'theme_mods_'*) printf '%s\n' "${SIM_THEME_MODS:-1}" ;;
	*) printf '0\n' ;;
esac
ANSWERSTUB

# A mysql client that records every invocation and delegates queries. Reading a
# dump from stdin is what the restore step does, so stdin is consumed here.
cat >"$bin/mysql" <<'MYSQLSTUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$MYSQL_LOG"
query=""
take_next=0
for arg in "$@"; do
	if [ "$take_next" -eq 1 ]; then query="$arg"; take_next=0; continue; fi
	case "$arg" in
		-e) take_next=1 ;;
		-*) : ;;
		*) : ;;
	esac
done
if [ -n "$query" ]; then
	"${LKC_ANSWER:-/bin/echo}" "$query"
	exit "${SIM_MYSQL_EXIT:-0}"
fi
cat >/dev/null
exit "${SIM_MYSQL_EXIT:-0}"
MYSQLSTUB

printf '#!/usr/bin/env bash\nexit 0\n' >"$bin/mysqldump"
chmod +x "$bin/lkc-answer-query" "$bin/mysql" "$bin/mysqldump"

RESTORE_ARGS=(--restored-wp-config="$backup_config")
RESTORE_TARGET_ARGS=(--target-db=lkc_drill_sim --yes-drop=lkc_drill_sim)

# run_restore [SIM_NAME=VALUE ...]
run_restore() {
	: >"$MYSQL_LOG"
	: >"$WP_LOG"
	OUTPUT="$(env \
		PATH="$bin:$PATH" \
		MYSQL_LOG="$MYSQL_LOG" WP_LOG="$WP_LOG" LKC_ANSWER="$bin/lkc-answer-query" \
		SIM_LIVE_CONFIG="$live_config" \
		SIM_RESTORED_TABLES= SIM_ROWS_RATINGS= SIM_ROWS_PUSH= SIM_ROWS_AUDIT= SIM_ROWS_CSP= \
		SIM_TITLES= SIM_EPISODES= SIM_VIDEO_META= SIM_PUSH_OPTION= SIM_ADS_OPTION= \
		SIM_THEME_MODS= SIM_CRON_OPTION= SIM_RESTORED_ENVELOPE= SIM_LIVE_PRIVATE= \
		SIM_DECRYPTABLE= SIM_DUMP_LINES= SIM_SOURCE_DB= SIM_STYLESHEET= SIM_MYSQL_EXIT= \
		SIM_NO_LIVE_KEY= \
		"$@" \
		bash "$RESTORE" --path="$install_root" \
		${RESTORE_TARGET_ARGS[@]+"${RESTORE_TARGET_ARGS[@]}"} \
		${RESTORE_ARGS[@]+"${RESTORE_ARGS[@]}"} 2>&1)"
	STATUS=$?
	FAILURES_TEXT="$(printf '%s\n' "$OUTPUT" | sed -n '/^Failed checks:/,$p')"
}

section 'a healthy drill completes and cleans up after itself'

run_restore
assert_exit_zero 'the whole quarterly drill'
assert_clean 'all four plugin tables are present'
assert_clean 'table wp_lkc_csp_reports can be read'
assert_clean 'catalog titles survived'
assert_clean 'episodes survived'
assert_clean 'episode video URLs survived'
assert_clean 'the push settings option survived'
assert_clean 'the advertising-code option survived'
assert_clean 'the theme mods survived'
assert_clean 'the pruning cron survived inside the cron option'
assert_clean 'the encrypted VAPID key survived the restore byte for byte'
assert_clean 'the live salts decrypt the stored key'
assert_clean 'all 8 salt definitions in the backup match the live'
assert_clean 'dropped the scratch database lkc_drill_sim'
assert_clean 'removed the dump file'

if printf '%s\n' "$OUTPUT" | grep -qE '^\| [0-9]{4}-[0-9]{2}-[0-9]{2} \| lkc_drill_sim \| [0-9]+ bytes \| [0-9]+/[0-9]+ \| OK \|$'; then
	ok 'it prints the log row the runbook asks for, in the runbook format'
else
	bad 'it prints the log row the runbook asks for' "$(printf '%s\n' "$OUTPUT" | grep '^|' | head -2)"
fi

if grep -q 'live_catalog_db' "$MYSQL_LOG"; then
	bad 'mysql is never pointed at the live database' "$(grep 'live_catalog_db' "$MYSQL_LOG" | head -2)"
else
	ok 'mysql is never pointed at the live database'
fi

if [ -s "$MYSQL_LOG" ] && ! grep -qv 'defaults-extra-file' "$MYSQL_LOG"; then
	ok 'every mysql call goes through the 0600 credentials file, never the command line'
else
	bad 'every mysql call goes through the 0600 credentials file' "$(grep -v 'defaults-extra-file' "$MYSQL_LOG" | head -2)"
fi

drops="$(grep -c 'DROP DATABASE' "$MYSQL_LOG")"
if [ "${drops:-0}" -eq 2 ]; then
	ok 'the scratch database is dropped to recreate it and again to clean up'
else
	bad 'the scratch database is dropped to recreate it and again to clean up' "found ${drops:-0}: $(cat "$MYSQL_LOG" | tr '\n' '|')"
fi

if grep -q 'db export' "$WP_LOG"; then
	ok 'the dump is taken with wp db export, not by hand'
else
	bad 'the dump is taken with wp db export' "$(tr '\n' '|' <"$WP_LOG")"
fi

section 'an incomplete restore is reported'

run_restore SIM_RESTORED_TABLES=3
assert_failed 'all four plugin tables are present'
assert_clean 'table wp_lkc_user_ratings can be read'

run_restore SIM_TITLES=0
assert_failed 'catalog titles survived'

run_restore SIM_EPISODES=0
assert_failed 'episodes survived'

run_restore SIM_VIDEO_META=0
assert_failed 'episode video URLs survived'

run_restore SIM_PUSH_OPTION=0
assert_failed 'the push settings option survived'

run_restore SIM_ADS_OPTION=0
assert_failed 'the advertising-code option survived'

run_restore SIM_CRON_OPTION=0
assert_failed 'the pruning cron survived inside the cron option'

run_restore SIM_THEME_MODS=0
assert_failed 'the theme mods survived'

run_restore SIM_DUMP_LINES=2
assert_failed 'the dump is not empty'

run_restore SIM_MYSQL_EXIT=1
if [ "$STATUS" -ne 0 ] && printf '%s\n' "$OUTPUT" | grep -qiE 'credentials|failed'; then
	ok 'a mysql that refuses the connection stops the drill instead of reporting success'
else
	bad 'a mysql that refuses the connection stops the drill' "exit $STATUS; $(printf '%s\n' "$OUTPUT" | tail -3 | tr '\n' '|')"
fi

section 'the salt dependency'

run_restore SIM_RESTORED_ENVELOPE='lkc1:v1:a-different-envelope'
assert_failed 'the encrypted VAPID key survived the restore byte for byte'

run_restore SIM_NO_LIVE_KEY=1
assert_skipped 'restored ciphertext matches the live ciphertext'

run_restore SIM_DECRYPTABLE=no
assert_failed 'the live salts decrypt the stored key'

write_config "$backup_config" 5
run_restore
assert_failed 'the backed-up wp-config.php defines all 8 salts'
write_config "$backup_config" 8

sed -i 's/simulated-salt-value-AUTH_KEY/rotated-after-the-backup-was-taken/' "$backup_config"
run_restore
assert_failed 'all 8 salt definitions in the backup match the live wp-config.php'
assert_none_failed 'the backed-up wp-config.php defines all 8 salts'
write_config "$backup_config" 8

write_config "$live_config" 5
run_restore
assert_skipped 'the backed-up wp-config.php carries the same salts'
write_config "$live_config" 8

RESTORE_ARGS=()
run_restore
assert_skipped 'the backed-up wp-config.php carries the same salts'
RESTORE_ARGS=(--restored-wp-config="$backup_config")

RESTORE_ARGS=(--restored-wp-config="$work/does-not-exist.php")
run_restore
assert_failed 'the backed-up wp-config.php was readable'
RESTORE_ARGS=(--restored-wp-config="$backup_config")

section 'the drill flags'

RESTORE_ARGS=(--verify-only --restored-wp-config="$backup_config")
RESTORE_TARGET_ARGS=()
run_restore
assert_skipped 'restored ciphertext matches the live ciphertext'
assert_clean 'all four plugin tables are present'
if grep -q 'CREATE DATABASE' "$MYSQL_LOG"; then
	bad '--verify-only never creates a scratch database' "$(cat "$MYSQL_LOG" | tr '\n' '|')"
else
	ok '--verify-only never creates a scratch database'
fi
if printf '%s\n' "$OUTPUT" | grep -qF 'live (verify only)'; then
	ok 'the log row says the live database was verified rather than restored'
else
	bad 'the log row says the live database was verified' "$(printf '%s\n' "$OUTPUT" | grep '^|' | head -2)"
fi
RESTORE_ARGS=(--restored-wp-config="$backup_config")
RESTORE_TARGET_ARGS=(--target-db=lkc_drill_sim --yes-drop=lkc_drill_sim)

RESTORE_ARGS=(--keep --restored-wp-config="$backup_config")
run_restore
drops="$(grep -c 'DROP DATABASE' "$MYSQL_LOG")"
if [ "${drops:-0}" -eq 1 ] && printf '%s\n' "$OUTPUT" | grep -q 'keeping lkc_drill_sim'; then
	ok '--keep leaves the scratch database in place and says how to drop it'
else
	bad '--keep leaves the scratch database in place' "drops=${drops:-0}; $(printf '%s\n' "$OUTPUT" | grep -i keep | head -2)"
fi
RESTORE_ARGS=(--restored-wp-config="$backup_config")

RESTORE_ARGS=(--keep-dump --restored-wp-config="$backup_config")
run_restore
if printf '%s\n' "$OUTPUT" | grep -q 'keeping the dump at'; then
	ok '--keep-dump leaves the dump in place and warns that it holds the whole database'
else
	bad '--keep-dump leaves the dump in place' "$(printf '%s\n' "$OUTPUT" | grep -i dump | head -3 | tr '\n' '|')"
fi
if printf '%s\n' "$OUTPUT" | grep -qF 'PASS  removed the dump file'; then
	bad '--keep-dump does not also delete the dump' 'it reported removing it'
else
	ok '--keep-dump does not also delete the dump'
fi
RESTORE_ARGS=(--restored-wp-config="$backup_config")
printf '\n=================================\n%d passed, %d failed\n' "$PASS" "$FAIL"
if [ "$FAIL" -gt 0 ]; then exit 1; fi
exit 0
