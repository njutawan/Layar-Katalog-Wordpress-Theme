#!/usr/bin/env bash
#
# Tests the destructive-command guards in the tests/wp/ scripts.
#
# Those scripts run against a real staging site, so the interesting question is
# not whether their checks work but whether they can be talked into touching
# something they should not. Every invocation below is refused, and the stubbed
# mysql client records what it was asked to do so the tests can prove that no
# DROP or CREATE ever reached it.
#
# Usage: bash tests/wp/guards.test.sh

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

work="$(mktemp -d "${TMPDIR:-/tmp}/lkc-guards.XXXXXX")"
cleanup() { rm -rf "$work"; }
trap cleanup EXIT

MYSQL_LOG="$work/mysql-invocations.log"
WP_LOG="$work/wp-invocations.log"
: >"$MYSQL_LOG"
: >"$WP_LOG"

# A stand-in for WP-CLI that answers only what the scripts read before their
# guards run, and records every invocation so a test can assert on it.
cat >"$work/wp" <<'WPSTUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$WP_LOG"
case "$1" in
	config)
		case "$2" in
			get)
				case "$3" in
					DB_NAME) printf 'live_catalog_db\n' ;;
					DB_USER) printf 'stub_user\n' ;;
					DB_PASSWORD) printf 'stub_password\n' ;;
					DB_HOST) printf 'localhost\n' ;;
					*) printf '\n' ;;
				esac
				;;
			path) printf '/srv/www/wp-config.php\n' ;;
			*) printf '\n' ;;
		esac
		;;
	eval) printf 'wp_\n' ;;
	core) printf '6.8\n' ;;
	option) printf 'https://example.test\n' ;;
	*) printf '\n' ;;
esac
WPSTUB

cat >"$work/mysql" <<'MYSQLSTUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$MYSQL_LOG"
exit 0
MYSQLSTUB

cp "$work/mysql" "$work/mysqldump"
chmod +x "$work/wp" "$work/mysql" "$work/mysqldump"

PATH="$work:$PATH"
export PATH MYSQL_LOG WP_LOG

REHEARSE="$repo/tests/wp/rehearse-restore.sh"
SMOKE="$repo/tests/wp/smoke-activate.sh"

# Run a command and report its exit code plus the combined output.
STATUS=0
OUTPUT=''
run_case() {
	STATUS=0
	OUTPUT="$("$@" 2>&1)" || STATUS=$?
}

assert_refused() {
	local label="$1"
	local needle="$2"
	if [ "$STATUS" -eq 2 ]; then
		ok "${label}: aborted with status 2"
	else
		bad "${label}: aborted with status 2" "exit status was ${STATUS}"
	fi
	case "$OUTPUT" in
		*"$needle"*) ok "${label}: explains why" ;;
		*) bad "${label}: explains why" "output did not mention [${needle}]: $(printf '%s' "$OUTPUT" | head -c 300)" ;;
	esac
}

assert_mysql_untouched() {
	if [ -s "$MYSQL_LOG" ]; then
		bad 'no mysql command was issued' "mysql was called with: $(head -c 300 "$MYSQL_LOG")"
	else
		ok 'no mysql command was issued'
	fi
}

section 'rehearse-restore.sh refuses to run without a target'
run_case bash "$REHEARSE"
assert_refused 'missing --target-db' '--target-db is required'
assert_mysql_untouched

section 'rehearse-restore.sh refuses to restore over the live database'
run_case bash "$REHEARSE" --target-db=live_catalog_db --yes-drop=live_catalog_db
assert_refused 'target equals the live database' 'refusing to run: --target-db is the live database'
assert_mysql_untouched

section 'rehearse-restore.sh refuses a name it cannot safely interpolate'
run_case bash "$REHEARSE" --target-db='drill; DROP DATABASE live_catalog_db' --yes-drop='drill; DROP DATABASE live_catalog_db'
assert_refused 'target contains shell metacharacters' 'must only contain letters, digits, underscore, dollar or dash'
assert_mysql_untouched

section 'rehearse-restore.sh requires the drop to be confirmed by name'
run_case bash "$REHEARSE" --target-db=lkc_drill_2026q4
assert_refused 'no --yes-drop' 'repeat its name with --yes-drop=lkc_drill_2026q4'
assert_mysql_untouched

run_case bash "$REHEARSE" --target-db=lkc_drill_2026q4 --yes-drop=lkc_drill_other
assert_refused '--yes-drop does not match the target' 'repeat its name with --yes-drop=lkc_drill_2026q4'
assert_mysql_untouched

section 'rehearse-restore.sh stops at the preflight when WP-CLI is missing'
empty_path="$(mktemp -d "$work/empty.XXXXXX")"
bash_bin="$(command -v bash)"
# An empty PATH is what makes the case deterministic: bash itself has to be
# invoked by absolute path, and everything the script looks up must be absent.
run_case env PATH="$empty_path" "$bash_bin" "$REHEARSE" --target-db=lkc_drill_2026q4 --yes-drop=lkc_drill_2026q4
if [ "$STATUS" -eq 2 ] && printf '%s' "$OUTPUT" | grep -q 'WP-CLI is not installed'; then
	ok 'without wp on PATH it aborts before any database work'
else
	bad 'without wp on PATH it aborts before any database work' "status ${STATUS}: $(printf '%s' "$OUTPUT" | head -c 200)"
fi
assert_mysql_untouched

section 'smoke-activate.sh never deactivates the plugin unless asked'
run_case bash "$SMOKE" --skip-http --path=/nonexistent
if grep -q 'plugin deactivate' "$WP_LOG"; then
	bad 'no deactivation without --activate' "wp was called with: $(grep 'plugin deactivate' "$WP_LOG" | head -n 1)"
else
	ok 'no deactivation without --activate'
fi
if grep -q 'rewrite flush' "$WP_LOG"; then
	bad 'no rewrite flush without --activate' 'wp rewrite flush was called'
else
	ok 'no rewrite flush without --activate'
fi
if [ "$STATUS" -le 1 ]; then
	ok "reports its findings and exits ${STATUS} (0 or 1, never a crash)"
else
	bad 'reports its findings and exits 0 or 1' "exit status was ${STATUS}: $(printf '%s' "$OUTPUT" | head -c 300)"
fi

section 'smoke-activate.sh rejects unknown arguments'
run_case bash "$SMOKE" --definitely-not-a-flag
if [ "$STATUS" -eq 2 ]; then
	ok 'an unknown flag exits 2 without running any check'
else
	bad 'an unknown flag exits 2 without running any check' "exit status was ${STATUS}"
fi

section 'both scripts document themselves'
if bash "$REHEARSE" --help | grep -q 'Quarterly backup-restore rehearsal'; then
	ok 'rehearse-restore.sh --help prints its usage'
else
	bad 'rehearse-restore.sh --help prints its usage'
fi
if bash "$SMOKE" --help | grep -q 'Activation smoke test'; then
	ok 'smoke-activate.sh --help prints its usage'
else
	bad 'smoke-activate.sh --help prints its usage'
fi
if bash "$REHEARSE" --help | grep -q 'set -uo pipefail'; then
	bad '--help stops at the comment block' 'it leaked executable lines into the usage text'
else
	ok '--help stops at the comment block'
fi

printf '\n=================================\n'
printf '%d passed, %d failed\n' "$PASS" "$FAIL"

[ "$FAIL" -gt 0 ] && exit 1
exit 0
